<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

/**
 * Where a run happened: host, process id and OS user (audit trail).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 */
final class RunHost
{
    /**
     * Columns describing the current process.
     *
     * @return array{host: string|null, pid: int|null, os_user: string|null}
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public static function describe(): array
    {
        $host = gethostname();
        $pid = getmypid();
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
            : (get_current_user() ?: null);

        return [
            'host' => $host !== false ? mb_substr($host, 0, 100) : null,
            'pid' => $pid !== false ? $pid : null,
            'os_user' => $user !== null ? mb_substr($user, 0, 64) : null,
        ];
    }
}
