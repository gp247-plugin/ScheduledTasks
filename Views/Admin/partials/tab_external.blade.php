{{--
    Outside-the-schedule tab: GP247 / plugin / site artisan commands run by an own
    crontab or by hand. View only.

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-external-runs
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
<div class="mb-3 space-y-1 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
    data-testid="scheduled-tasks-external-note">
    <p>{{ gp247_language_render($l.'external_not_controllable') }}</p>
    <p class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'external_blind_spot') }}</p>
</div>

<x-gp247::table
    :headers="[
        gp247_language_render($l.'col_task'),
        gp247_language_render($l.'col_last_run'),
        gp247_language_render($l.'col_runs_7d'),
        gp247_language_render($l.'col_host'),
        '',
    ]"
    :empty="$externalTasks->isEmpty() ? gp247_language_render($l.'external_empty') : null">
    @foreach ($externalTasks as $task)
        @php($run = $task->runs->first())
        <tr wire:key="external-{{ $task->id }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/50" data-testid="scheduled-task-external-{{ $task->id }}">
            <td class="px-4 py-3 text-sm">
                <span class="break-all font-mono font-semibold text-gray-800 dark:text-gray-100">{{ $task->task_key }}</span>
                @if ($task->isNew() && $task->source === 'external')
                    <x-gp247::badge color="blue">{{ gp247_language_render($l.'badge_new') }}</x-gp247::badge>
                @endif
                @if ($run?->arguments && $run->displayArguments() !== $task->task_key)
                    <div class="mt-0.5 break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $run->displayArguments() }}</div>
                @endif
            </td>
            <td class="px-4 py-3 text-sm">
                @include('Plugins/ScheduledTasks::Admin.partials.run_status', ['status' => $run?->status])
                @if ($run)
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $run->started_at->format('d/m/Y H:i') }} · {{ gp247_language_render($l.'col_exit') }} {{ $run->exit_code ?? '—' }}</div>
                @endif
            </td>
            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $task->external_runs_7d }}</td>
            <td class="px-4 py-3 break-all text-xs text-gray-500 dark:text-gray-400">{{ $run?->host ?? '—' }} / {{ $run?->os_user ?? '—' }}</td>
            <td class="px-4 py-3">
                <div class="flex justify-end">
                    <x-gp247::button size="sm" variant="secondary" class="whitespace-nowrap" :href="gp247_route_admin('admin_scheduled_tasks.detail', ['id' => $task->id])">{{ gp247_language_render($l.'detail') }}</x-gp247::button>
                </div>
            </td>
        </tr>
    @endforeach
</x-gp247::table>
