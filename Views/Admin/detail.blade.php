{{--
    Scheduled task — detail screen. Same layout as the source screen: information and
    configuration side by side, run history below. The configuration card appears
    only for admins allowed to configure and only for controllable tasks.

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-cron-config
    @aidlc-story US-plugin-scheduled-tasks-toggle
    @aidlc-story US-plugin-scheduled-tasks-run-now
    @aidlc-story US-plugin-scheduled-tasks-run-history
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
@php($next = $task->nextRunAt())
<div class="space-y-4" x-data="{ runId: {{ $task->id }} }">
    <div>
        <a href="{{ gp247_route_admin('admin_scheduled_tasks.index') }}" class="text-sm text-gray-500 hover:text-blue-600 dark:text-gray-400"
            data-testid="scheduled-task-back">&larr; {{ gp247_language_render($l.'title') }}</a>
        <h2 class="mt-1 break-all font-mono text-lg font-bold text-gray-800 dark:text-gray-100">{{ $task->task_key }}</h2>
        <div class="mt-1 flex flex-wrap gap-1">
            @if ($task->isControllable())
                @if ($task->is_enabled)
                    <x-gp247::badge color="green">{{ gp247_language_render($l.'badge_enabled') }}</x-gp247::badge>
                @else
                    <x-gp247::badge color="red" :solid="true">{{ gp247_language_render($l.'badge_disabled') }}</x-gp247::badge>
                @endif
            @endif
            @if ($task->isNew())
                <x-gp247::badge color="blue">{{ gp247_language_render($l.'badge_new') }}</x-gp247::badge>
            @endif
            @if ($task->isGone())
                <x-gp247::badge color="gray">{{ gp247_language_render($l.'badge_gone') }}</x-gp247::badge>
            @endif
        </div>
    </div>

    @include('Plugins/ScheduledTasks::Admin.partials.health')

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-gp247::card :title="gp247_language_render($l.'info_title')">
            <dl class="grid grid-cols-3 gap-2 text-sm">
                <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'info_command') }}</dt>
                <dd class="col-span-2 break-all font-mono text-gray-800 dark:text-gray-100">{{ $task->command }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'info_kind') }}</dt>
                <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ gp247_language_render($l.'kind.'.$task->kind) }} · {{ gp247_language_render($l.'source.'.$task->source) }}</dd>
                @if ($task->source === 'schedule')
                    <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'code_cron') }}</dt>
                    <dd class="col-span-2 font-mono text-gray-800 dark:text-gray-100">{{ $task->code_cron }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'cron_effective') }}</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">
                        <span class="font-mono">{{ $task->effectiveCron() }}</span>
                        @if ($task->hasOverride())
                            <x-gp247::badge color="purple" data-testid="scheduled-task-override-{{ $task->id }}">{{ gp247_language_render($l.'badge_override') }}</x-gp247::badge>
                        @endif
                        @if ($next)
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'next_run', ['time' => $next->format('d/m/Y H:i')]) }}</div>
                        @endif
                        @if ($task->codeCronChanged())
                            <div class="text-xs text-amber-700 dark:text-amber-400">{{ gp247_language_render($l.'code_cron_changed', ['from' => $task->override_base_cron, 'to' => $task->code_cron]) }}</div>
                        @endif
                    </dd>
                    @if ($task->timezone)
                        <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'info_timezone') }}</dt>
                        <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $task->timezone }}</dd>
                    @endif
                @endif
                <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'info_discovered') }}</dt>
                <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $task->discovered_at?->format('d/m/Y H:i') }}</dd>
                @if ($task->source === 'schedule')
                    <dt class="text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'info_last_seen') }}</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $task->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                @endif
            </dl>
            @unless ($task->isControllable())
                <p class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-2 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    {{ gp247_language_render($l.'external_not_controllable') }}
                </p>
            @endunless
            @if ($task->is_repeatable)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'repeatable_hint') }}</p>
            @endif
        </x-gp247::card>

        @if ($canManage && $task->isControllable())
            <x-gp247::card :title="gp247_language_render($l.'config_title')">
                <div class="space-y-3 text-sm">
                    @if ($task->canChangeCron())
                        <form wire:submit="saveCron" data-testid="scheduled-task-cron-form">
                            <label for="scheduled-task-cron" class="mb-1 block font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'cron_label') }}</label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input id="scheduled-task-cron" type="text" maxlength="100" autocomplete="off" wire:model.live.debounce.300ms="cron"
                                    class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                    data-testid="scheduled-task-cron-input">
                                <x-gp247::button type="submit" data-testid="scheduled-task-cron-save">{{ gp247_language_render($l.'cron_save') }}</x-gp247::button>
                            </div>
                            @error('cron')
                                <p class="mt-1 text-red-700 dark:text-red-400" data-testid="scheduled-task-cron-error">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'cron_help') }}</p>
                            <div class="mt-2 text-xs" data-testid="scheduled-task-cron-preview" aria-live="polite">
                                @if ($preview['valid'])
                                    <div class="font-medium text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'cron_preview_title') }}</div>
                                    <div class="font-mono text-gray-600 dark:text-gray-300">{{ implode(' · ', $preview['next_runs']) }}</div>
                                    @if ($preview['denser_than_code'])
                                        <div class="text-amber-700 dark:text-amber-400">{{ gp247_language_render($l.'cron_denser_warning') }}</div>
                                    @endif
                                @else
                                    <div class="text-red-700 dark:text-red-400">{{ gp247_language_render($l.'cron_invalid') }}</div>
                                @endif
                            </div>
                        </form>
                    @endif

                    <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                        @if ($task->override_cron)
                            <x-gp247::button variant="secondary" wire:click="resetCron" data-testid="scheduled-task-cron-reset">{{ gp247_language_render($l.'cron_reset') }}</x-gp247::button>
                        @endif
                        <x-gp247::button :variant="$task->is_enabled ? 'danger' : 'success'" wire:click="toggle({{ $task->id }})"
                            data-testid="scheduled-task-toggle-{{ $task->id }}">{{ gp247_language_render($l.($task->is_enabled ? 'toggle_off' : 'toggle_on')) }}</x-gp247::button>
                        @if ($task->isRunNowPending())
                            <x-gp247::badge color="amber" data-testid="scheduled-task-run-now-waiting-{{ $task->id }}">{{ gp247_language_render($l.'run_now_waiting') }}</x-gp247::badge>
                        @elseif ($schedulerStale)
                            <x-gp247::button variant="secondary" disabled title="{{ gp247_language_render($l.'run_now_locked') }}"
                                data-testid="scheduled-task-run-now-locked-{{ $task->id }}">{{ gp247_language_render($l.'run_now') }}</x-gp247::button>
                        @else
                            <x-gp247::button data-testid="scheduled-task-run-now-{{ $task->id }}"
                                x-on:click="$dispatch('open-modal', 'scheduled-task-run-now')">{{ gp247_language_render($l.'run_now') }}</x-gp247::button>
                        @endif
                        @if ($task->isNew())
                            <x-gp247::button variant="secondary" wire:click="acknowledge({{ $task->id }})"
                                data-testid="scheduled-task-acknowledge-{{ $task->id }}">{{ gp247_language_render($l.'acknowledge') }}</x-gp247::button>
                        @endif
                    </div>
                </div>
            </x-gp247::card>

            <x-gp247::modal name="scheduled-task-run-now" :title="gp247_language_render($l.'run_now')">
                <p class="text-sm text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'run_now_confirm') }}</p>
                <x-slot:footer>
                    <x-gp247::button variant="secondary" x-on:click="$dispatch('close-modal', 'scheduled-task-run-now')">{{ gp247_language_render($l.'cancel') }}</x-gp247::button>
                    <x-gp247::button data-testid="scheduled-task-run-now-confirm"
                        x-on:click="$wire.requestRun(runId); $dispatch('close-modal', 'scheduled-task-run-now')">{{ gp247_language_render($l.'run_now') }}</x-gp247::button>
                </x-slot:footer>
            </x-gp247::modal>
        @endif
    </div>

    <x-gp247::card :title="gp247_language_render($l.'history_title')">
        <div class="mb-3 flex flex-wrap items-center justify-end gap-2">
            <select wire:model.live="status" aria-label="{{ gp247_language_render($l.'col_status') }}"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                data-testid="scheduled-task-history-filter">
                <option value="">{{ gp247_language_render($l.'filter_all') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option }}">{{ gp247_language_render($l.'status.'.$option) }}</option>
                @endforeach
            </select>
        </div>

        <x-gp247::table
            :headers="[
                gp247_language_render($l.'col_status'),
                gp247_language_render($l.'col_started'),
                gp247_language_render($l.'col_duration'),
                gp247_language_render($l.'col_trigger'),
                gp247_language_render($l.'col_exit'),
                gp247_language_render($l.'col_host'),
            ]"
            :empty="$runs->isEmpty() ? gp247_language_render($l.'history_empty') : null">
            @foreach ($runs as $run)
                <tr wire:key="run-{{ $run->id }}" class="align-top" data-testid="scheduled-task-history-row">
                    <td class="px-4 py-3 whitespace-nowrap">@include('Plugins/ScheduledTasks::Admin.partials.run_status', ['status' => $run->status])</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-200">{{ $run->started_at->format('d/m/Y H:i:s') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">@include('Plugins/ScheduledTasks::Admin.partials.duration', ['ms' => $run->duration_ms])</td>
                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                        {{ gp247_language_render($l.'trigger.'.$run->trigger) }}@if ($run->triggeredBy) · {{ $run->triggeredBy->name }}@endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $run->exit_code ?? '—' }}</td>
                    <td class="px-4 py-3 break-all text-xs text-gray-400 dark:text-gray-500">{{ $run->host }}{{ $run->os_user ? ' / '.$run->os_user : '' }}</td>
                </tr>
                @if ($run->arguments || $run->error_message || $run->output)
                    <tr wire:key="run-output-{{ $run->id }}">
                        <td colspan="6" class="px-4 py-2">
                            <details>
                                <summary class="cursor-pointer text-xs text-blue-700">{{ gp247_language_render($l.'col_output') }}</summary>
                                @if ($run->arguments)
                                    <div class="mt-1 break-all font-mono text-xs text-gray-600 dark:text-gray-300">$ {{ $run->displayArguments() }}</div>
                                @endif
                                @if ($run->error_message)
                                    <pre class="mt-1 whitespace-pre-wrap break-all rounded bg-red-50 p-2 text-xs text-red-700 dark:bg-red-900/10">{{ $run->error_message }}</pre>
                                @endif
                                @if ($run->output)
                                    <pre class="mt-1 max-h-80 overflow-y-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $run->output }}</pre>
                                @endif
                            </details>
                        </td>
                    </tr>
                @endif
            @endforeach
        </x-gp247::table>

        <div class="mt-3">{{ $runs->links() }}</div>
    </x-gp247::card>
</div>
