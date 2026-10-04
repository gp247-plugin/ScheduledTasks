<?php
/**
 * ScheduledTasks DEFAULT settings.
 *
 * This file belongs to the plugin and is OVERWRITTEN by every 1-click update, so
 * never edit it on a site. To change a value, create `config/scheduled-tasks.php`
 * in the site root with the keys you want to override — that file survives plugin
 * updates and always wins over these defaults (see Support\Settings).
 */
return [
    // Days of run history to keep; older rows are removed by scheduled-tasks:purge-runs.
    'retention_days' => 90,

    // A run still "running" after this many minutes is shown as abandoned.
    'stale_after_minutes' => 120,

    // The scheduler is considered stopped after this many minutes without a pass.
    'scheduler_stale_minutes' => 3,

    // A queue worker is considered stopped after this many minutes without a heartbeat.
    'worker_stale_minutes' => 5,

    // Bytes of command output kept per run (the tail).
    'output_max_bytes' => 16384,

    // Record GP247 / plugin / site artisan commands that run outside the scheduler.
    'record_external' => true,

    // Extra commands (e.g. from packages) to record when run outside the scheduler,
    // as name patterns: ['backup:*', 'horizon:snapshot']. GP247 / App commands, closure
    // commands of routes/console.php and commands on the schedule are always recorded.
    'external_commands' => [],

    // Extra arguments appended when a task is run with "Run now", keyed by task key,
    // e.g. ['report:send' => '--force']. Never taken from a web request.
    'run_now_args' => [],
];
