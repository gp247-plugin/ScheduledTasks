<?php

namespace App\GP247\Plugins\ScheduledTasks\Support;

/**
 * Reports a problem of the governance layer without ever throwing.
 *
 * WHY: the plugin runs inside schedule:run and every artisan command; a failure
 * to report must not become a failure of the task (NFR-AVAIL-scheduled-tasks-fail-open).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-governance
 */
final class SafeReport
{
    /**
     * Log the throwable through GP247's reporter (Laravel's as a fallback).
     *
     * @param \Throwable $e
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public static function throwable(\Throwable $e): void
    {
        try {
            $message = '[ScheduledTasks] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            function_exists('gp247_report') ? gp247_report($message) : report($e);
        } catch (\Throwable) {
            // Reporting must never interrupt the scheduler or a command.
        }
    }
}
