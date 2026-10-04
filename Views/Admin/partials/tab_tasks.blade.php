{{--
    Tasks tab: one table row per task of the schedule (the shared table scrolls
    horizontally on narrow screens). Columns: task · schedule · state · last run · actions.
    Only Tailwind classes present in the prebuilt admin bundle are used (gp247.md §3a).

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-list-status
    @aidlc-story US-plugin-scheduled-tasks-toggle
    @aidlc-story US-plugin-scheduled-tasks-run-now
    @aidlc-story US-plugin-scheduled-tasks-auto-discovery
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
<x-gp247::table
    :headers="[
        gp247_language_render($l.'col_task'),
        gp247_language_render($l.'col_schedule'),
        gp247_language_render($l.'col_state'),
        gp247_language_render($l.'col_last_run'),
        gp247_language_render($l.'col_actions'),
    ]"
    :empty="$tasks->isEmpty() ? gp247_language_render($l.'no_tasks') : null">
    @foreach ($tasks as $task)
        @php($run = $task->latestRun)
        @php($next = $task->nextRunAt())
        <tr wire:key="task-{{ $task->id }}" data-testid="scheduled-task-row-{{ $task->id }}"
            @class(['align-top hover:bg-gray-50 dark:hover:bg-gray-700/50', 'bg-red-50 dark:bg-red-900/10' => !$task->is_enabled])>
            <td class="px-4 py-3">
                <a href="{{ gp247_route_admin('admin_scheduled_tasks.detail', ['id' => $task->id]) }}"
                    class="font-mono text-sm font-semibold text-gray-800 hover:text-blue-600 dark:text-gray-100">{{ $task->task_key }}</a>
                @if ($task->description && $task->description !== $task->task_key)
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $task->description }}</div>
                @endif
                @if (!$task->is_enabled || $task->isNew() || $task->isGone())
                    <div class="mt-1 flex flex-wrap gap-1">
                        @unless ($task->is_enabled)
                            <x-gp247::badge color="red" :solid="true" data-testid="scheduled-task-disabled-{{ $task->id }}">{{ gp247_language_render($l.'badge_disabled') }}</x-gp247::badge>
                        @endunless
                        @if ($task->isNew())
                            <x-gp247::badge color="blue" data-testid="scheduled-task-new-{{ $task->id }}">{{ gp247_language_render($l.'badge_new') }}</x-gp247::badge>
                        @endif
                        @if ($task->isGone())
                            <x-gp247::badge color="gray" data-testid="scheduled-task-gone-{{ $task->id }}">{{ gp247_language_render($l.'badge_gone') }}</x-gp247::badge>
                        @endif
                    </div>
                @endif
            </td>

            <td class="px-4 py-3 whitespace-nowrap text-sm">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-gray-800 dark:text-gray-100">{{ $task->effectiveCron() ?? '—' }}</span>
                    @if ($task->hasOverride())
                        <x-gp247::badge color="purple" data-testid="scheduled-task-override-{{ $task->id }}">{{ gp247_language_render($l.'badge_override') }}</x-gp247::badge>
                    @endif
                </div>
                @if ($task->hasOverride())
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'code_cron') }}: <span class="font-mono">{{ $task->code_cron }}</span></div>
                @endif
                @if ($task->codeCronChanged())
                    <div class="mt-0.5 text-xs text-amber-700 dark:text-amber-400" data-testid="scheduled-task-code-changed-{{ $task->id }}">
                        {{ gp247_language_render($l.'code_cron_changed', ['from' => $task->override_base_cron, 'to' => $task->code_cron]) }}
                    </div>
                @endif
                @if ($next)
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'next_run', ['time' => $next->format('d/m H:i')]) }}</div>
                @endif
            </td>

            <td class="px-4 py-3 whitespace-nowrap">
                @if ($canManage && $task->isControllable())
                    <button type="button" wire:click="toggle({{ $task->id }})" data-testid="scheduled-task-toggle-{{ $task->id }}"
                        title="{{ gp247_language_render($l.($task->is_enabled ? 'toggle_off' : 'toggle_on')) }}"
                        @class([
                            'rounded border px-2 py-1 text-xs font-medium',
                            'border-green-200 bg-green-50 text-green-700 hover:bg-green-100 dark:border-green-800 dark:bg-green-900/20' => $task->is_enabled,
                            'border-gray-300 bg-gray-100 text-gray-600 hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300' => !$task->is_enabled,
                        ])>
                        {{ gp247_language_render($l.($task->is_enabled ? 'badge_enabled' : 'badge_disabled')) }}
                    </button>
                @else
                    <x-gp247::badge :color="$task->is_enabled ? 'green' : 'red'">
                        {{ gp247_language_render($l.($task->is_enabled ? 'badge_enabled' : 'badge_disabled')) }}
                    </x-gp247::badge>
                @endif
            </td>

            <td class="px-4 py-3 whitespace-nowrap text-sm">
                @include('Plugins/ScheduledTasks::Admin.partials.run_status', ['status' => $run?->status])
                @if ($run)
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ $run->started_at->format('d/m H:i') }} ·
                        @include('Plugins/ScheduledTasks::Admin.partials.duration', ['ms' => $run->duration_ms])
                        · {{ gp247_language_render($l.'trigger.'.$run->trigger) }}@if ($run->triggeredBy) {{ gp247_language_render($l.'by', ['name' => $run->triggeredBy->name]) }}@endif
                    </div>
                @endif
            </td>

            <td class="px-4 py-3 whitespace-nowrap">
                <div class="flex items-center justify-end gap-1">
                    @if ($task->isRunNowPending())
                        <x-gp247::badge color="amber" data-testid="scheduled-task-run-now-waiting-{{ $task->id }}">{{ gp247_language_render($l.'run_now_waiting') }}</x-gp247::badge>
                    @elseif ($canManage && $task->isControllable())
                        @if ($schedulerStale)
                            <x-gp247::button size="sm" variant="secondary" disabled title="{{ gp247_language_render($l.'run_now_locked') }}"
                                class="cursor-not-allowed whitespace-nowrap" data-testid="scheduled-task-run-now-locked-{{ $task->id }}">&#9654; {{ gp247_language_render($l.'run_now') }}</x-gp247::button>
                        @else
                            <x-gp247::button size="sm" class="whitespace-nowrap" data-testid="scheduled-task-run-now-{{ $task->id }}"
                                x-on:click="runId = {{ $task->id }}; $dispatch('open-modal', 'scheduled-task-run-now')">&#9654; {{ gp247_language_render($l.'run_now') }}</x-gp247::button>
                        @endif
                    @endif
                    @if ($canManage && $task->isNew())
                        <x-gp247::button size="sm" variant="secondary" wire:click="acknowledge({{ $task->id }})" class="whitespace-nowrap"
                            data-testid="scheduled-task-acknowledge-{{ $task->id }}">&#10003; {{ gp247_language_render($l.'acknowledge') }}</x-gp247::button>
                    @endif
                    <x-gp247::button size="sm" variant="secondary" class="whitespace-nowrap" :href="gp247_route_admin('admin_scheduled_tasks.detail', ['id' => $task->id])"
                        data-testid="scheduled-task-detail-{{ $task->id }}">{{ gp247_language_render($l.'detail') }}</x-gp247::button>
                </div>
            </td>
        </tr>
    @endforeach
</x-gp247::table>
