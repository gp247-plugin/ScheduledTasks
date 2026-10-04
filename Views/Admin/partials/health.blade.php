{{--
    Scheduler health banners: never ran (with the cron line to copy), stopped, alive;
    plus "the whole schedule is paused" (schedule:pause).

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-worker-health
    @aidlc-adr ADR-plugin-scheduled-tasks-no-cron-site-behaviour
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
@if ($schedulerLastTick === null)
    <x-gp247::alert type="warning" :title="gp247_language_render($l.'scheduler_never_title')" data-testid="scheduler-never-banner">
        <p>{{ gp247_language_render($l.'scheduler_never') }}</p>
        <code class="mt-2 block break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-100"
            data-testid="scheduler-cron-line">{{ $cronLine }}</code>
    </x-gp247::alert>
@elseif ($schedulerStale)
    <x-gp247::alert type="error" data-testid="scheduler-down-banner">
        <p>{{ gp247_language_render($l.'scheduler_down', ['time' => $schedulerLastTick->format('d/m/Y H:i')]) }}</p>
        <code class="mt-2 block break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-100"
            data-testid="scheduler-cron-line">{{ $cronLine }}</code>
    </x-gp247::alert>
@else
    <p class="text-xs text-gray-500 dark:text-gray-400" data-testid="scheduler-alive">
        <span class="inline-block h-2 w-2 rounded-full bg-green-500" aria-hidden="true"></span>
        {{ gp247_language_render($l.'scheduler_alive', ['time' => $schedulerLastTick->format('d/m/Y H:i')]) }}
        @if ($schedulerInterval)
            · <span data-testid="scheduler-interval">{{ gp247_language_render($l.'scheduler_interval', ['minutes' => max(1, (int) round($schedulerInterval / 60))]) }}</span>
        @endif
        @if (count($schedulerHosts) > 0)
            · <span data-testid="scheduler-hosts">{{ gp247_language_render($l.'scheduler_hosts', ['hosts' => implode(', ', array_column($schedulerHosts, 'host'))]) }}</span>
        @endif
    </p>
@endif

@if ($schedulePaused)
    <x-gp247::alert type="warning" data-testid="schedule-paused-banner">
        {{ gp247_language_render($l.'schedule_paused') }}
    </x-gp247::alert>
@endif
