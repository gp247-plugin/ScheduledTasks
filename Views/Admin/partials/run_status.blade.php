{{--
    Badge for a run status ($status may be null = never ran).

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-list-status
--}}
@php
    $colors = ['running' => 'blue', 'success' => 'green', 'failed' => 'red', 'skipped' => 'amber', 'abandoned' => 'red'];
@endphp
@if ($status)
    <x-gp247::badge :color="$colors[$status] ?? 'gray'" :solid="$status === 'abandoned'">
        {{ gp247_language_render('Plugins/ScheduledTasks::lang.status.'.$status) }}
    </x-gp247::badge>
@else
    <x-gp247::badge color="gray">{{ gp247_language_render('Plugins/ScheduledTasks::lang.status.never') }}</x-gp247::badge>
@endif
