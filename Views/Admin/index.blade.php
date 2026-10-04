{{--
    Scheduled tasks — list screen. Same layout as the source screen: health banners,
    then three tabs (Tasks / Outside the schedule / Queue). Write controls are shown
    only to admins allowed to configure; every write action is re-authorized server side.

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-list-status
    @aidlc-story US-plugin-scheduled-tasks-external-runs
    @aidlc-story US-plugin-scheduled-tasks-queue-monitor
    @aidlc-story US-plugin-scheduled-tasks-worker-health
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
<div class="space-y-4" x-data="{ runId: null, retryUuid: null }">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'subtitle') }}</p>

    @include('Plugins/ScheduledTasks::Admin.partials.health')

    @if (count($unidentified) > 0)
        <x-gp247::alert type="warning" data-testid="scheduled-tasks-unidentified">
            {{ gp247_language_render($l.'unidentified_warning', ['count' => count($unidentified), 'list' => implode(', ', $unidentified)]) }}
        </x-gp247::alert>
    @endif

    @if ($newCount > 0)
        <x-gp247::alert type="info" data-testid="scheduled-tasks-new-count">
            {{ gp247_language_render($l.'new_count', ['count' => $newCount]) }}
        </x-gp247::alert>
    @endif

    @unless ($canManage)
        <x-gp247::alert type="info" data-testid="scheduled-tasks-readonly">
            {{ gp247_language_render($l.'readonly_hint') }}
        </x-gp247::alert>
    @endunless

    <x-gp247::tabs :default="$tab" :tabs="[
        'tasks' => gp247_language_render($l.'tab_tasks'),
        'external' => gp247_language_render($l.'tab_external'),
        'queue' => gp247_language_render($l.'tab_queue'),
    ]">
        <div x-show="tab === 'tasks'" x-cloak data-testid="scheduled-tasks-pane-tasks">
            @include('Plugins/ScheduledTasks::Admin.partials.tab_tasks')
        </div>
        <div x-show="tab === 'external'" x-cloak data-testid="scheduled-tasks-pane-external">
            @include('Plugins/ScheduledTasks::Admin.partials.tab_external')
        </div>
        <div x-show="tab === 'queue'" x-cloak data-testid="scheduled-tasks-pane-queue">
            @include('Plugins/ScheduledTasks::Admin.partials.tab_queue')
        </div>
    </x-gp247::tabs>

    @if ($canManage)
        <x-gp247::modal name="scheduled-task-run-now" :title="gp247_language_render($l.'run_now')">
            <p class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'run_now_confirm') }}</p>
            <x-slot:footer>
                <x-gp247::button variant="secondary" x-on:click="$dispatch('close-modal', 'scheduled-task-run-now')">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
                <x-gp247::button data-testid="scheduled-task-run-now-confirm"
                    x-on:click="$wire.requestRun(runId); $dispatch('close-modal', 'scheduled-task-run-now')">{{ gp247_language_render($l.'run_now') }}</x-gp247::button>
            </x-slot:footer>
        </x-gp247::modal>

        <x-gp247::modal name="failed-job-retry" :title="gp247_language_render($l.'failed_job_retry')">
            <p class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'failed_job_retry_confirm') }}</p>
            <x-slot:footer>
                <x-gp247::button variant="secondary" x-on:click="$dispatch('close-modal', 'failed-job-retry')">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
                <x-gp247::button data-testid="failed-job-retry-confirm"
                    x-on:click="$wire.retryFailedJob(retryUuid); $dispatch('close-modal', 'failed-job-retry')">{{ gp247_language_render($l.'failed_job_retry') }}</x-gp247::button>
            </x-slot:footer>
        </x-gp247::modal>
    @endif
</div>
