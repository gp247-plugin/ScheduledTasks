{{--
    Human duration of a run ($ms may be null).

    @aidlc-unit plugin-scheduled-tasks
    @aidlc-story US-plugin-scheduled-tasks-run-history
--}}
@php($durationText = match (true) {
    $ms === null => '—',
    $ms < 1000 => $ms . ' ms',
    $ms < 60000 => number_format($ms / 1000, 1) . ' s',
    default => intdiv($ms, 60000) . ' min ' . intdiv($ms % 60000, 1000) . ' s',
})
{{ $durationText }}
