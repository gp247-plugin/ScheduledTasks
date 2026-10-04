<?php

namespace App\GP247\Plugins\ScheduledTasks\Livewire;

use App\GP247\Plugins\ScheduledTasks\Livewire\Concerns\ScheduledTasksScreen;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Scheduling\CronSchedule;
use App\GP247\Plugins\ScheduledTasks\Services\ScheduledTaskService;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Detail screen of one task: information, cron form with live preview, actions,
 * and the run history (20 per page, filterable by status).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-cron-config
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 */
class TaskDetail extends GP247AdminComponent
{
    use ScheduledTasksScreen;
    use WithPagination;

    private const PER_PAGE = 20;
    private const PREVIEW_RUNS = 5;

    /**
     * Resolves to "<admin>/scheduled-tasks" when no request path is available.
     *
     * @var string|null
     */
    protected ?string $permission = 'admin_scheduled-tasks';

    public int $taskId;

    public string $cron = '';

    #[Url]
    public string $status = '';

    /** @var array{valid: bool, next_runs: list<string>, denser_than_code: bool} */
    public array $preview = ['valid' => false, 'next_runs' => [], 'denser_than_code' => false];

    /**
     * Load the task (404 when unknown) and seed the cron field.
     *
     * @param int $id Route parameter.
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function mount(int $id = 0): void
    {
        parent::mount();
        $task = ScheduledTask::findOrFail($id);
        $this->taskId = $task->id;
        $this->cron = (string) $task->effectiveCron();
        $this->refreshPreview();
    }

    /**
     * Recompute the preview while the admin types.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function updatedCron(): void
    {
        $this->resetErrorBag('cron');
        $this->refreshPreview();
    }

    /**
     * Reset paging when the status filter changes.
     *
     * @return void
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Save the cron override.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function saveCron(): void
    {
        $this->authorizeAction('saveCron');
        $task = $this->task();

        if (mb_strlen($this->cron) > 100 || !(new CronSchedule($this->cron))->isValid()) {
            $this->addError('cron', gp247_language_render('Plugins/ScheduledTasks::lang.cron_invalid'));

            return;
        }

        $this->respond(app(ScheduledTaskService::class)->updateCron($task, $this->cron), 'msg_cron_saved');
    }

    /**
     * Restore the schedule written in the code.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function resetCron(): void
    {
        $this->authorizeAction('resetCron');
        $task = $this->task();
        $ok = app(ScheduledTaskService::class)->resetCron($task);
        $this->cron = (string) $task->fresh()->effectiveCron();
        $this->refreshPreview();
        $this->respond($ok, 'msg_cron_reset');
    }

    /**
     * Render the detail screen.
     *
     * @return \Illuminate\Contracts\View\View
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function render()
    {
        $task = $this->task();
        $status = in_array($this->status, ScheduledTaskRun::STATUSES, true) ? $this->status : '';

        $runs = $task->runs()
            ->with('triggeredBy')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('Plugins/ScheduledTasks::Admin.detail', $this->healthData() + [
            'task' => $task,
            'runs' => $runs,
            'canManage' => $this->canManage(),
            'statuses' => ScheduledTaskRun::STATUSES,
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('Plugins/ScheduledTasks::lang.title'),
        ]);
    }

    /**
     * The task of this screen, fresh from the database.
     *
     * @return ScheduledTask
     */
    private function task(): ScheduledTask
    {
        return ScheduledTask::findOrFail($this->taskId);
    }

    /**
     * Next runs + density warning for the current cron input.
     *
     * @return void
     */
    private function refreshPreview(): void
    {
        $task = $this->task();
        $cron = new CronSchedule($this->cron);
        $timezone = $task->timezone ?: (string) config('app.timezone');

        $this->preview = [
            'valid' => $cron->isValid(),
            'next_runs' => array_map(
                static fn ($run) => $run->format('d/m/Y H:i'),
                $cron->nextRuns(self::PREVIEW_RUNS, $timezone)
            ),
            'denser_than_code' => $cron->isDenserThan($task->code_cron),
        ];
    }
}
