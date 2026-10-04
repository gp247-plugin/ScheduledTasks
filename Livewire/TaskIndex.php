<?php

namespace App\GP247\Plugins\ScheduledTasks\Livewire;

use App\GP247\Plugins\ScheduledTasks\Livewire\Concerns\ScheduledTasksScreen;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Scheduling\ScheduleGovernor;
use App\GP247\Plugins\ScheduledTasks\Services\QueueMonitor;
use App\GP247\Plugins\ScheduledTasks\Services\ScheduledTaskService;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

/**
 * List screen: Tasks / Outside the schedule / Queue tabs, with scheduler health.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-list-status
 * @aidlc-story US-plugin-scheduled-tasks-external-runs
 * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
 */
class TaskIndex extends GP247AdminComponent
{
    use ScheduledTasksScreen;

    public const TABS = ['tasks', 'external', 'queue'];

    /**
     * Resolves to "<admin>/scheduled-tasks" when no request path is available.
     *
     * @var string|null
     */
    protected ?string $permission = 'admin_scheduled-tasks';

    #[Url]
    public string $tab = 'tasks';

    /**
     * Authorize, normalise the tab, and discover tasks when no cron runs.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-list-status
     */
    public function mount(): void
    {
        parent::mount();
        if (!in_array($this->tab, self::TABS, true)) {
            $this->tab = 'tasks';
        }
        $this->discoverWhenIdle();
    }

    /**
     * Push a failed job back onto its queue.
     *
     * @param string $uuid
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-queue-monitor
     */
    public function retryFailedJob(string $uuid): void
    {
        $this->authorizeAction('retryFailedJob');
        $this->respond(app(ScheduledTaskService::class)->retryFailedJob($uuid), 'msg_job_retried', 'msg_job_unknown');
    }

    /**
     * Render the list screen.
     *
     * @return \Illuminate\Contracts\View\View
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-list-status
     */
    public function render()
    {
        $queue = app(QueueMonitor::class);
        $tasks = $this->scheduleTasks();

        return view('Plugins/ScheduledTasks::Admin.index', $this->healthData() + [
            'tasks' => $tasks,
            'externalTasks' => $this->externalTasks(),
            'newCount' => $tasks->filter->isNew()->count(),
            'unidentified' => (array) Cache::get(ScheduleGovernor::UNIDENTIFIED_KEY, []),
            'canManage' => $this->canManage(),
            'queueState' => $queue->state(),
            'queueDriver' => $queue->driver(),
            'pending' => $queue->pending(),
            'hasFailedTable' => $queue->hasFailedTable(),
            'failedCount' => $queue->failedCount24h(),
            'failedJobs' => $queue->failedJobs(),
            'workers' => $queue->workers(),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('Plugins/ScheduledTasks::lang.title'),
        ]);
    }

    /**
     * Schedule tasks: disabled first, then newly discovered, then by key.
     *
     * @return Collection<int, ScheduledTask>
     */
    private function scheduleTasks(): Collection
    {
        return ScheduledTask::query()
            ->where('source', ScheduledTask::SOURCE_SCHEDULE)
            ->with(['latestRun.triggeredBy'])
            ->get()
            ->sortBy(fn (ScheduledTask $task) => [$task->is_enabled ? 1 : 0, $task->isNew() ? 0 : 1, $task->task_key])
            ->values();
    }

    /**
     * Commands that ran outside the scheduler, with their latest outside run.
     *
     * @return Collection<int, ScheduledTask>
     */
    private function externalTasks(): Collection
    {
        $external = fn ($q) => $q->where('trigger', ScheduledTaskRun::TRIGGER_EXTERNAL);

        return ScheduledTask::query()
            ->whereHas('runs', $external)
            ->with(['runs' => fn ($q) => $external($q)->latest('started_at')->limit(1)])
            ->withCount(['runs as external_runs_7d' => fn ($q) => $external($q)->where('started_at', '>=', now()->subDays(7))])
            ->orderBy('task_key')
            ->get();
    }
}
