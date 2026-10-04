<?php

namespace App\GP247\Plugins\ScheduledTasks\Livewire\Concerns;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Services\ScheduledTaskService;
use App\GP247\Plugins\ScheduledTasks\Services\SchedulerHealth;
use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

/**
 * Behaviour shared by the list and detail screens: write permission, the actions
 * both screens offer, scheduler health, and read-only discovery when no cron runs.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-access-control
 */
trait ScheduledTasksScreen
{
    /**
     * Whether the current admin may change configuration on this screen.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function canManage(): bool
    {
        try {
            return app(AuthorizeAdminAction::class)
                ->authorize(app(AdminUserContract::class), $this->authScreenUri(), 'toggle')
                ->isAllowed();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Switch a task on / off.
     *
     * @param int $id
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-toggle
     */
    public function toggle(int $id): void
    {
        $this->authorizeAction('toggle');
        $this->respond(app(ScheduledTaskService::class)->toggle(ScheduledTask::findOrFail($id)), 'msg_toggled');
    }

    /**
     * Ask the scheduler to run a task on its next pass.
     *
     * @param int $id
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-now
     */
    public function requestRun(int $id): void
    {
        $this->authorizeAction('requestRun');
        $this->respond(app(ScheduledTaskService::class)->requestRun(ScheduledTask::findOrFail($id)), 'msg_run_requested', 'msg_run_refused');
    }

    /**
     * Mark a newly discovered task as seen.
     *
     * @param int $id
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function acknowledge(int $id): void
    {
        $this->authorizeAction('acknowledge');
        $this->respond(app(ScheduledTaskService::class)->acknowledge(ScheduledTask::findOrFail($id)), 'msg_acknowledged');
    }

    /**
     * Scheduler health for the banner partial.
     *
     * @return array<string, mixed>
     */
    protected function healthData(): array
    {
        $health = app(SchedulerHealth::class);

        return [
            'schedulerLastTick' => $health->lastTick(),
            'schedulerStale' => $health->isStale(),
            'schedulerInterval' => $health->intervalSeconds(),
            'schedulerHosts' => $health->hosts(),
            'schedulePaused' => $health->isPaused(),
            'cronLine' => $health->cronLine(),
        ];
    }

    /**
     * When no scheduler pass is recent, list the tasks anyway without running any.
     *
     * WHY bootstrap the console kernel: a web request does not load
     * routes/console.php, where many sites declare their schedule.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     * @aidlc-adr ADR-plugin-scheduled-tasks-no-cron-site-behaviour
     */
    protected function discoverWhenIdle(): void
    {
        if (!app(SchedulerHealth::class)->isStale()) {
            return;
        }

        try {
            if (!app()->runningInConsole()) {
                app(Kernel::class)->bootstrap();
            }
            app(Schedule::class)->events();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Toast the outcome of an action.
     *
     * @param bool   $ok
     * @param string $successKey Lang key (plugin file) on success.
     * @param string $failKey    Lang key on refusal.
     * @return void
     */
    protected function respond(bool $ok, string $successKey, string $failKey = 'msg_not_allowed'): void
    {
        $this->notify($ok ? 'success' : 'error', gp247_language_render('Plugins/ScheduledTasks::lang.' . ($ok ? $successKey : $failKey)));
    }
}
