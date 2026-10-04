<?php

namespace App\GP247\Plugins\ScheduledTasks\Services;

use GP247\Core\Models\AdminLog;

/**
 * One admin_log row per configuration change, carrying the old and new values
 * (core's own Livewire log records the call, not what it changed).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-access-control
 */
class AuditLog
{
    /**
     * Write the audit row.
     *
     * @param string               $action e.g. scheduled_task_cron_updated.
     * @param array<string, mixed> $data   Task key / uuid and the old → new values.
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function write(string $action, array $data): void
    {
        $request = request();

        AdminLog::create([
            'user_id' => (string) (admin()->user()->id ?? ''),
            'path' => GP247_ADMIN_PREFIX . '/scheduled-tasks',
            'method' => 'LIVEWIRE',
            'ip' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'input' => json_encode(['action' => $action] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
