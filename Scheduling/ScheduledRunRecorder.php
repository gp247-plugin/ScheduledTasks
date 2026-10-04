<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Services\SchedulerHealth;
use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;

/**
 * Writes one history row per scheduled run from the framework's own events.
 *
 * A pass blocked by the overlap lock is normally filtered out (ScheduledTaskSkipped);
 * if the lock is taken between that check and the run, Starting/Finished still fire
 * with a null exit code (when not in background) — both become "skipped".
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
class ScheduledRunRecorder
{
    /** @var \WeakMap<Event, int> Run row of the event's current execution. */
    private \WeakMap $current;

    /**
     * @param ScheduleGovernor $governor
     */
    public function __construct(private readonly ScheduleGovernor $governor)
    {
        $this->current = new \WeakMap();
    }

    /**
     * Open a "running" row.
     *
     * @param ScheduledTaskStarting $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function starting(ScheduledTaskStarting $event): void
    {
        $meta = $this->governor->metaFor($event->task);
        if ($meta === null) {
            return;
        }

        // WHY: Event::run() returns early on an overlap without resetting the exit
        // code; a leftover value from an earlier run would hide the skip.
        $event->task->exitCode = null;

        try {
            $run = ScheduledTaskRun::create([
                'scheduled_task_id' => $meta['task_id'],
                'trigger' => $meta['trigger'],
                'triggered_by' => $meta['requested_by'],
                'status' => ScheduledTaskRun::STATUS_RUNNING,
                'started_at' => now(),
            ] + RunHost::describe());
            $this->current[$event->task] = $run->id;
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Record a pass blocked by the previous run still holding the overlap lock.
     *
     * WHY here: withoutOverlapping() adds a "skip while the lock exists" filter, so a
     * blocked pass fires ScheduledTaskSkipped instead of Starting. The same event fires
     * for a disabled task and a paused schedule every minute — those leave no row.
     *
     * @param ScheduledTaskSkipped $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function skipped(ScheduledTaskSkipped $event): void
    {
        $task = $event->task;
        $meta = $this->governor->metaFor($task);
        if ($meta === null || !$this->governor->allows($meta['key']) || !$task->withoutOverlapping) {
            return;
        }

        try {
            if (app(SchedulerHealth::class)->isPaused() || !$task->mutex->exists($task)) {
                return;
            }
            $now = now();
            ScheduledTaskRun::create([
                'scheduled_task_id' => $meta['task_id'],
                'trigger' => $meta['trigger'],
                'triggered_by' => $meta['requested_by'],
                'status' => ScheduledTaskRun::STATUS_SKIPPED,
                'started_at' => $now,
                'finished_at' => $now,
                'duration_ms' => 0,
            ] + RunHost::describe());
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Close the row with the final status and the output tail.
     *
     * @param ScheduledTaskFinished $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $run = $this->currentRun($task);
        if ($run === null) {
            return;
        }

        try {
            if ($task->exitCode === null && $task->runInBackground) {
                return; // closed by ScheduledBackgroundTaskFinished
            }

            $status = match (true) {
                $task->exitCode === null => ScheduledTaskRun::STATUS_SKIPPED,
                (int) $task->exitCode === 0 => ScheduledTaskRun::STATUS_SUCCESS,
                default => ScheduledTaskRun::STATUS_FAILED,
            };

            $run->fill([
                'status' => $status,
                'exit_code' => $task->exitCode,
                'finished_at' => now(),
                'duration_ms' => (int) round(((float) $event->runtime) * 1000),
                'output' => $status === ScheduledTaskRun::STATUS_SKIPPED ? null : $this->outputTail($task),
            ])->save();

            if ($status !== ScheduledTaskRun::STATUS_SKIPPED) {
                $this->clearRunNowFlag($run);
            }
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Mark the row failed with the error (callbacks that throw never reach "finished").
     *
     * @param ScheduledTaskFailed $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function failed(ScheduledTaskFailed $event): void
    {
        $run = $this->currentRun($event->task);
        if ($run === null) {
            return;
        }

        try {
            $run->fill([
                'status' => ScheduledTaskRun::STATUS_FAILED,
                'exit_code' => $event->task->exitCode ?? $run->exit_code ?? 1,
                'error_message' => mb_substr($event->exception->getMessage(), 0, 4000),
                'finished_at' => $run->finished_at ?? now(),
                'duration_ms' => $run->duration_ms ?? max(0, (int) $run->started_at->diffInMilliseconds(now())),
            ])->save();
            $this->clearRunNowFlag($run);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Close the latest running row of a task that ran in the background.
     *
     * @param ScheduledBackgroundTaskFinished $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $meta = $this->governor->metaFor($event->task);
        if ($meta === null) {
            return;
        }

        try {
            $run = ScheduledTaskRun::query()
                ->where('scheduled_task_id', $meta['task_id'])
                ->whereIn('status', [ScheduledTaskRun::STATUS_RUNNING, ScheduledTaskRun::STATUS_ABANDONED])
                ->latest('started_at')
                ->first();
            if ($run === null) {
                return;
            }
            $exit = $event->task->exitCode;
            $run->fill([
                'status' => (int) $exit === 0 ? ScheduledTaskRun::STATUS_SUCCESS : ScheduledTaskRun::STATUS_FAILED,
                'exit_code' => $exit,
                'finished_at' => now(),
                'duration_ms' => max(0, (int) $run->started_at->diffInMilliseconds(now())),
                'output' => $this->outputTail($event->task),
            ])->save();
            $this->clearRunNowFlag($run);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * The row opened for this execution of the event.
     *
     * @param Event $task
     * @return ScheduledTaskRun|null
     */
    private function currentRun(Event $task): ?ScheduledTaskRun
    {
        $id = $this->current[$task] ?? null;
        if ($id === null) {
            return null;
        }

        try {
            return ScheduledTaskRun::find($id);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            return null;
        }
    }

    /**
     * Lower the run-now flag, unless it was raised again after this run started.
     *
     * @param ScheduledTaskRun $run
     * @return void
     */
    private function clearRunNowFlag(ScheduledTaskRun $run): void
    {
        if ($run->trigger !== ScheduledTaskRun::TRIGGER_MANUAL) {
            return;
        }

        ScheduledTask::query()
            ->whereKey($run->scheduled_task_id)
            ->whereNotNull('run_now_requested_at')
            ->where('run_now_requested_at', '<=', $run->started_at)
            ->update(['run_now_requested_at' => null, 'run_now_requested_by' => null]);
    }

    /**
     * Last bytes of the output file of the event, when there is one.
     *
     * @param Event $task
     * @return string|null
     */
    private function outputTail(Event $task): ?string
    {
        $path = $this->governor->metaFor($task)['output'] ?? null;
        if (!$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $limit = Settings::int('output_max_bytes', 1024);
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        if ($size > $limit) {
            fseek($handle, -$limit, SEEK_END);
        }
        $tail = stream_get_contents($handle);
        fclose($handle);

        $tail = $tail === false ? '' : mb_scrub($tail, 'UTF-8');

        return $tail === '' ? null : $tail;
    }
}
