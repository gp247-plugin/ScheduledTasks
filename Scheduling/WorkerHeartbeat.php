<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers when each queue worker last looped, at most every 30 seconds per queue.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-worker-health
 */
class WorkerHeartbeat
{
    public const KEY_PREFIX = 'scheduled-tasks:heartbeat:worker:';
    public const INDEX_KEY = 'scheduled-tasks:workers';
    private const THROTTLE_SECONDS = 30;

    /** @var array<string, int> Last write per queue in this process. */
    private array $lastWrite = [];

    /**
     * Record a worker loop.
     *
     * @param Looping $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function record(Looping $event): void
    {
        $id = $event->connectionName . ':' . $event->queue;
        $now = now()->getTimestamp();
        if (isset($this->lastWrite[$id]) && $now - $this->lastWrite[$id] < self::THROTTLE_SECONDS) {
            return;
        }
        $this->lastWrite[$id] = $now;

        try {
            Cache::forever(self::KEY_PREFIX . $id, $now);
            $index = (array) Cache::get(self::INDEX_KEY, []);
            if (!in_array($id, $index, true)) {
                $index[] = $id;
                Cache::forever(self::INDEX_KEY, $index);
            }
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }
}
