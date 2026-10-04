<?php

namespace App\GP247\Plugins\ScheduledTasks\Services;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Scheduling\CronSchedule;
use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use Illuminate\Support\Facades\Artisan;

/**
 * Configuration changes made from the admin screens. Each one checks the business
 * rule, writes the change and one audit row; nothing here ever runs a task.
 *
 * Authorization (who may call these) is enforced by the Livewire components.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-cron-config
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
class ScheduledTaskService
{
    /**
     * @param AuditLog        $audit
     * @param SchedulerHealth $health
     * @param QueueMonitor    $queue
     */
    public function __construct(
        private readonly AuditLog $audit,
        private readonly SchedulerHealth $health,
        private readonly QueueMonitor $queue,
    ) {
    }

    /**
     * Override the code schedule with a valid five-field cron.
     *
     * @param ScheduledTask $task
     * @param string        $cron
     * @return bool False when the task cannot take a cron or the cron is invalid.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function updateCron(ScheduledTask $task, string $cron): bool
    {
        $cron = ScheduledTask::normalizeCron($cron);
        if (!$task->canChangeCron() || !(new CronSchedule($cron))->isValid()) {
            return false;
        }

        // WHY: saving the code schedule means "follow the code" — store no override,
        // so a later change of the code schedule is picked up instead of pinned.
        if ($cron === ScheduledTask::normalizeCron((string) $task->code_cron)) {
            return $task->override_cron === null || $this->resetCron($task);
        }

        $old = $task->override_cron;
        $task->update([
            'override_cron' => $cron,
            'override_base_cron' => $task->code_cron,
            'updated_by' => $this->actorId(),
        ]);
        $this->audit->write('scheduled_task_cron_updated', [
            'task' => $task->task_key,
            'old' => ['override_cron' => $old],
            'new' => ['override_cron' => $cron],
        ]);

        return true;
    }

    /**
     * Go back to the code schedule.
     *
     * @param ScheduledTask $task
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function resetCron(ScheduledTask $task): bool
    {
        if (!$task->isControllable() || $task->override_cron === null) {
            return false;
        }

        $old = $task->override_cron;
        $task->update(['override_cron' => null, 'override_base_cron' => null, 'updated_by' => $this->actorId()]);
        $this->audit->write('scheduled_task_cron_reset', [
            'task' => $task->task_key,
            'old' => ['override_cron' => $old],
            'new' => ['override_cron' => null],
        ]);

        return true;
    }

    /**
     * Switch a task on or off.
     *
     * @param ScheduledTask $task
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-toggle
     */
    public function toggle(ScheduledTask $task): bool
    {
        if (!$task->isControllable()) {
            return false;
        }

        $old = (bool) $task->is_enabled;
        $task->update(['is_enabled' => !$old, 'updated_by' => $this->actorId()]);
        $this->audit->write($old ? 'scheduled_task_disabled' : 'scheduled_task_enabled', [
            'task' => $task->task_key,
            'old' => ['is_enabled' => $old],
            'new' => ['is_enabled' => !$old],
        ]);

        return true;
    }

    /**
     * Raise the run-now flag (the next scheduler pass runs the task once).
     *
     * @param ScheduledTask $task
     * @return bool False when not controllable, already pending, or the scheduler is not running.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-now
     */
    public function requestRun(ScheduledTask $task): bool
    {
        if (!$task->isControllable() || $task->isRunNowPending() || $this->health->isStale()) {
            return false;
        }

        $at = now();
        $task->update(['run_now_requested_at' => $at, 'run_now_requested_by' => $this->actorId()]);
        $this->audit->write('scheduled_task_run_requested', [
            'task' => $task->task_key,
            'old' => ['run_now_requested_at' => null],
            'new' => ['run_now_requested_at' => $at->toDateTimeString()],
        ]);

        return true;
    }

    /**
     * Mark a newly discovered task as seen.
     *
     * @param ScheduledTask $task
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function acknowledge(ScheduledTask $task): bool
    {
        if (!$task->isNew()) {
            return false;
        }

        $at = now();
        $task->update(['acknowledged_at' => $at, 'acknowledged_by' => $this->actorId()]);
        $this->audit->write('scheduled_task_acknowledged', [
            'task' => $task->task_key,
            'old' => ['acknowledged_at' => null],
            'new' => ['acknowledged_at' => $at->toDateTimeString()],
        ]);

        return true;
    }

    /**
     * Push a failed job back onto its queue (`queue:retry <uuid>`). Never deletes a job.
     *
     * @param string $uuid
     * @return bool False when the job is unknown.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function retryFailedJob(string $uuid): bool
    {
        $job = $this->queue->findFailed($uuid);
        if ($job === null) {
            return false;
        }

        try {
            Artisan::call('queue:retry', ['id' => [$job->uuid]]);
        } catch (\Throwable $e) {
            SafeReport::throwable($e);

            return false;
        }
        $this->audit->write('failed_job_retried', [
            'uuid' => $job->uuid,
            'job' => class_basename((string) (json_decode((string) $job->payload, true)['displayName'] ?? '')),
            'queue' => $job->connection . ':' . $job->queue,
        ]);

        return true;
    }

    /**
     * Id of the admin making the change.
     *
     * @return string|null
     */
    private function actorId(): ?string
    {
        $id = admin()->user()->id ?? null;

        return $id !== null ? (string) $id : null;
    }
}
