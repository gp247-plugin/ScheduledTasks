<?php

namespace App\GP247\Plugins\ScheduledTasks\Commands;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Illuminate\Console\Command;

/**
 * Removes run-history rows older than the retention.
 *
 * What it does: deletes rows of the plugin's run-history table only, where the run
 * started more than --days days ago. Target: the site's GP247 database.
 * Recoverable: no (restore from a database backup). Without --execute it only counts.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 */
class PurgeRuns extends Command
{
    private const BATCH = 1000;

    protected $signature = 'scheduled-tasks:purge-runs
        {--days= : Keep this many days (default: the plugin retention setting)}
        {--execute : Actually delete; without it the command only counts}';

    protected $description = 'Delete scheduled-task run history older than the retention (dry-run unless --execute)';

    /**
     * Count, and with --execute delete in batches, the expired history rows.
     *
     * @return int
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function handle(): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : Settings::int('retention_days');
        $cutoff = now()->subDays($days);
        $expired = fn () => ScheduledTaskRun::query()->where('started_at', '<', $cutoff);

        $count = $expired()->count();
        if (!$this->option('execute')) {
            $this->line(sprintf('%d run(s) older than %d day(s) would be deleted. Re-run with --execute to delete them.', $count, $days));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = $expired()->orderBy('id')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += ScheduledTaskRun::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::BATCH);

        $this->line(sprintf('%d run(s) older than %d day(s) deleted.', $deleted, $days));

        return self::SUCCESS;
    }
}
