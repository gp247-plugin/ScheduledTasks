<?php

namespace App\GP247\Plugins\ScheduledTasks\Services;

use App\GP247\Plugins\ScheduledTasks\Models\SchedulerHeartbeat;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Is the scheduler running at all, how often, and on which machines?
 * (Many GP247 sites have no cron — NFR-AVAIL-001.)
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-worker-health
 * @aidlc-adr ADR-plugin-scheduled-tasks-no-cron-site-behaviour
 */
class SchedulerHealth
{
    /** Cache flag set by `php artisan schedule:pause` (Laravel 11+). */
    public const PAUSED_KEY = 'illuminate:schedule:paused';

    /** @var Collection<int, SchedulerHeartbeat>|null Rows read once per instance. */
    private ?Collection $rows = null;

    /**
     * Record a scheduler pass for this machine (called by every schedule:run).
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function record(): void
    {
        $host = mb_substr((string) (gethostname() ?: 'unknown'), 0, 100);
        $now = now();
        $row = SchedulerHeartbeat::query()->where('host', $host)->first();
        $ticks = array_slice(array_merge($row?->ticks() ?? [], [$now->getTimestamp()]), -SchedulerHeartbeat::KEEP_TICKS);

        if ($row === null) {
            SchedulerHeartbeat::query()->create(['host' => $host, 'last_tick_at' => $now, 'recent_ticks' => json_encode($ticks)]);
        } else {
            $row->update(['last_tick_at' => $now, 'recent_ticks' => json_encode($ticks)]);
        }
        $this->rows = null;
    }

    /**
     * Time of the last scheduler pass on any machine, or null when none was ever seen.
     *
     * @return CarbonImmutable|null
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function lastTick(): ?CarbonImmutable
    {
        $latest = $this->latestRow();

        return $latest ? $this->toLocal($latest->last_tick_at) : null;
    }

    /**
     * Typical seconds between passes (median of the latest machine's recent passes).
     *
     * @return int|null Null until at least two intervals were seen.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function intervalSeconds(): ?int
    {
        $ticks = $this->latestRow()?->ticks() ?? [];
        $gaps = [];
        for ($i = 1, $n = count($ticks); $i < $n; $i++) {
            $gap = $ticks[$i] - $ticks[$i - 1];
            if ($gap > 0) {
                $gaps[] = $gap;
            }
        }
        if (count($gaps) < 2) {
            return null;
        }

        // WHY the median: one manual "schedule:run" between two cron passes must not
        // halve the measured interval.
        sort($gaps);
        $middle = intdiv(count($gaps), 2);

        return count($gaps) % 2 ? $gaps[$middle] : intdiv($gaps[$middle - 1] + $gaps[$middle], 2);
    }

    /**
     * Minutes without a pass after which the scheduler counts as stopped.
     *
     * @return int max(configured threshold, two measured intervals).
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function staleAfterMinutes(): int
    {
        $configured = Settings::int('scheduler_stale_minutes');
        $interval = $this->intervalSeconds();

        return $interval === null ? $configured : max($configured, (int) ceil(2 * $interval / 60));
    }

    /**
     * No pass at all, or none for longer than the threshold.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function isStale(): bool
    {
        $last = $this->lastTick();

        return $last === null || $last->lt(now()->subMinutes($this->staleAfterMinutes()));
    }

    /**
     * Machines that ran the scheduler, latest first.
     *
     * @return list<array{host: string, last_tick: CarbonImmutable}>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function hosts(): array
    {
        return $this->rows()
            ->map(fn (SchedulerHeartbeat $row) => ['host' => (string) $row->host, 'last_tick' => $this->toLocal($row->last_tick_at)])
            ->values()
            ->all();
    }

    /**
     * The whole schedule is paused with `schedule:pause`.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-list-status
     */
    public function isPaused(): bool
    {
        try {
            return (bool) Cache::get(self::PAUSED_KEY, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The standard cron entry for this site.
     *
     * @return string
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function cronLine(): string
    {
        return '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1';
    }

    /**
     * Heartbeat rows, latest pass first; empty when the table cannot be read.
     *
     * @return Collection<int, SchedulerHeartbeat>
     */
    private function rows(): Collection
    {
        if ($this->rows === null) {
            try {
                $this->rows = SchedulerHeartbeat::query()->orderByDesc('last_tick_at')->get();
            } catch (\Throwable) {
                $this->rows = new Collection();
            }
        }

        return $this->rows;
    }

    /**
     * @return SchedulerHeartbeat|null
     */
    private function latestRow(): ?SchedulerHeartbeat
    {
        return $this->rows()->first();
    }

    /**
     * @param \DateTimeInterface $at
     * @return CarbonImmutable
     */
    private function toLocal(\DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'));
    }
}
