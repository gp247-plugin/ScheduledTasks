{{--
    Queue tab: worker heartbeats, pending jobs per queue, failed jobs (retry only —
    never delete). Every state the site can be in (sync, other driver, missing table)
    is said in words rather than shown as zeros.

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-queue-monitor
    @aidlc-story US-plugin-scheduled-tasks-worker-health
--}}
@php($l = 'Plugins/ScheduledTasks::lang.')
<div class="space-y-4">
    <x-gp247::card :title="gp247_language_render($l.'workers_title')">
        @forelse ($workers as $worker)
            <div class="flex flex-wrap items-center gap-2 py-1 text-sm"
                data-testid="worker-{{ $worker['alive'] ? 'alive' : ($worker['last_seen'] ? 'stale' : 'never') }}-{{ $worker['slug'] }}">
                <span class="font-mono text-gray-800 dark:text-gray-100">{{ $worker['connection'] }}:{{ $worker['queue'] }}</span>
                @if ($worker['alive'])
                    <x-gp247::badge color="green">{{ gp247_language_render($l.'worker_alive', ['time' => $worker['last_seen']->format('d/m H:i')]) }}</x-gp247::badge>
                @elseif ($worker['last_seen'])
                    <x-gp247::badge color="red">{{ gp247_language_render($l.'worker_stale', ['time' => $worker['last_seen']->format('d/m H:i')]) }}</x-gp247::badge>
                @else
                    <x-gp247::badge color="gray">{{ gp247_language_render($l.'worker_never') }}</x-gp247::badge>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'workers_empty') }}</p>
        @endforelse
    </x-gp247::card>

    <x-gp247::card :title="gp247_language_render($l.'pending_title')">
        @if ($queueState !== 'database')
            <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="queue-state">
                {{ gp247_language_render($l.'queue_'.$queueState, ['driver' => $queueDriver]) }}
            </p>
        @else
            @forelse ($pending as $row)
                <div class="flex flex-wrap gap-2 py-1 text-sm" data-testid="queue-pending-{{ $row->queue }}">
                    <span class="font-mono text-gray-800 dark:text-gray-100">{{ $row->queue }}</span>
                    <span class="text-gray-700 dark:text-gray-200">{{ gp247_language_render($l.'pending_total', ['count' => $row->total]) }}</span>
                    <span class="text-gray-500 dark:text-gray-400">· {{ gp247_language_render($l.'pending_oldest', ['minutes' => $row->oldest_minutes]) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400" data-testid="queue-state">{{ gp247_language_render($l.'pending_empty') }}</p>
            @endforelse
        @endif
    </x-gp247::card>

    <x-gp247::card :title="gp247_language_render($l.'failed_title')">
        @if (!$hasFailedTable)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render($l.'failed_no_table') }}</p>
        @else
            <p @class(['mb-2 text-xs', 'font-semibold text-red-700' => $failedCount > 0, 'text-gray-500 dark:text-gray-400' => $failedCount === 0])
                data-testid="queue-failed-24h">{{ gp247_language_render($l.'failed_24h', ['count' => $failedCount]) }}</p>
            <x-gp247::table :empty="count($failedJobs) === 0 ? gp247_language_render($l.'failed_empty') : null">
                @foreach ($failedJobs as $job)
                    <tr wire:key="failed-{{ $job->uuid }}" class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/50" data-testid="failed-job-{{ $job->uuid }}">
                        <td class="px-4 py-3 break-all font-mono text-sm text-gray-800 dark:text-gray-100">{{ $job->job }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">{{ $job->connection }}:{{ $job->queue }}</td>
                        <td class="px-4 py-3 break-all text-sm text-red-700">{{ $job->error }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $job->failed_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3">
                            @if ($canManage)
                                <div class="flex justify-end">
                                    <x-gp247::button size="sm" class="whitespace-nowrap" data-testid="failed-job-retry-{{ $job->uuid }}"
                                        x-on:click="retryUuid = @js($job->uuid); $dispatch('open-modal', 'failed-job-retry')">&#8635; {{ gp247_language_render($l.'failed_job_retry') }}</x-gp247::button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-gp247::table>
            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ gp247_language_render($l.'failed_no_delete') }}</p>
        @endif
    </x-gp247::card>
</div>
