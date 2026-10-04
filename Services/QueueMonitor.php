<?php

namespace App\GP247\Plugins\ScheduledTasks\Services;

use App\GP247\Plugins\ScheduledTasks\Scheduling\WorkerHeartbeat;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only view of the queue: pending jobs, failed jobs, worker heartbeats.
 *
 * WHY every guard: GP247 sites run with `sync`, `database`, redis…, with or without
 * the jobs tables — check the real condition, never infer it.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
 */
class QueueMonitor
{
    public const STATE_SYNC = 'sync';
    public const STATE_DATABASE = 'database';
    public const STATE_UNSUPPORTED = 'unsupported';
    public const STATE_MISSING_TABLE = 'missing_table';

    private const FAILED_LIST_LIMIT = 50;

    /**
     * What the screen can say about the default queue.
     *
     * @return string One of the STATE_* constants.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function state(): string
    {
        $driver = $this->driver();
        if ($driver === 'sync') {
            return self::STATE_SYNC;
        }
        if ($driver !== 'database') {
            return self::STATE_UNSUPPORTED;
        }

        return $this->hasTable($this->jobsConnection(), $this->jobsTable()) ? self::STATE_DATABASE : self::STATE_MISSING_TABLE;
    }

    /**
     * Driver name of the default connection.
     *
     * @return string
     */
    public function driver(): string
    {
        $connection = (string) config('queue.default');

        return (string) config('queue.connections.' . $connection . '.driver', $connection);
    }

    /**
     * Pending jobs per queue (database driver only).
     *
     * @return list<object{queue: string, total: int, oldest_minutes: int}>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function pending(): array
    {
        if ($this->state() !== self::STATE_DATABASE) {
            return [];
        }

        $now = now()->getTimestamp();

        return $this->jobsConnection()->table($this->jobsTable())
            ->selectRaw('queue, COUNT(*) AS total, MIN(available_at) AS oldest')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(static fn ($row) => (object) [
                'queue' => (string) $row->queue,
                'total' => (int) $row->total,
                'oldest_minutes' => max(0, intdiv($now - (int) $row->oldest, 60)),
            ])
            ->all();
    }

    /**
     * Whether failed jobs can be read.
     *
     * @return bool
     */
    public function hasFailedTable(): bool
    {
        return $this->hasTable($this->failedConnection(), $this->failedTable());
    }

    /**
     * Failed jobs of the last 24 hours.
     *
     * @return int
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function failedCount24h(): int
    {
        if (!$this->hasFailedTable()) {
            return 0;
        }

        return $this->failedConnection()->table($this->failedTable())
            ->where('failed_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * The latest failed jobs, without their payload.
     *
     * @return list<object{uuid: string, job: string, connection: string, queue: string, error: string, failed_at: CarbonImmutable}>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function failedJobs(): array
    {
        if (!$this->hasFailedTable()) {
            return [];
        }

        return $this->failedConnection()->table($this->failedTable())
            ->select(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->whereNotNull('uuid')
            ->orderByDesc('failed_at')
            ->limit(self::FAILED_LIST_LIMIT)
            ->get()
            ->map(fn ($row) => (object) [
                'uuid' => (string) $row->uuid,
                'job' => $this->jobName((string) $row->payload),
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 300),
                'failed_at' => CarbonImmutable::parse($row->failed_at),
            ])
            ->all();
    }

    /**
     * One failed job by UUID (for retry), or null.
     *
     * @param string $uuid
     * @return object|null
     */
    public function findFailed(string $uuid): ?object
    {
        if (!$this->hasFailedTable() || !Str::isUuid($uuid)) {
            return null;
        }

        return $this->failedConnection()->table($this->failedTable())
            ->where('uuid', $uuid)
            ->first(['uuid', 'connection', 'queue', 'payload']);
    }

    /**
     * Workers seen by the heartbeat listener.
     *
     * @return list<array{connection: string, queue: string, slug: string, last_seen: CarbonImmutable|null, alive: bool}>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function workers(): array
    {
        $staleAfter = now()->subMinutes(Settings::int('worker_stale_minutes'));
        $workers = [];
        foreach ((array) Cache::get(WorkerHeartbeat::INDEX_KEY, []) as $id) {
            [$connection, $queue] = array_pad(explode(':', (string) $id, 2), 2, '');
            $timestamp = Cache::get(WorkerHeartbeat::KEY_PREFIX . $id);
            $lastSeen = is_numeric($timestamp)
                ? CarbonImmutable::createFromTimestamp((int) $timestamp)->setTimezone((string) config('app.timezone'))
                : null;
            $workers[] = [
                'connection' => $connection,
                'queue' => $queue,
                'slug' => Str::slug($connection . '-' . $queue),
                'last_seen' => $lastSeen,
                'alive' => $lastSeen !== null && $lastSeen->gte($staleAfter),
            ];
        }

        return $workers;
    }

    /**
     * Display name of a queued job from its payload.
     *
     * @param string $payload
     * @return string
     */
    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return class_basename((string) ($decoded['displayName'] ?? $decoded['job'] ?? '?'));
    }

    /**
     * @return Connection
     */
    private function jobsConnection(): Connection
    {
        $name = config('queue.connections.' . config('queue.default') . '.connection');

        return DB::connection($name ?: null);
    }

    /**
     * @return string
     */
    private function jobsTable(): string
    {
        return (string) config('queue.connections.' . config('queue.default') . '.table', 'jobs');
    }

    /**
     * @return Connection
     */
    private function failedConnection(): Connection
    {
        return DB::connection(config('queue.failed.database') ?: null);
    }

    /**
     * @return string
     */
    private function failedTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /**
     * @param Connection $connection
     * @param string     $table
     * @return bool
     */
    private function hasTable(Connection $connection, string $table): bool
    {
        try {
            return $connection->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
