<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Commands\PurgeRuns;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

/**
 * Wires the governance layer into the application. Called from Provider.php only
 * while the plugin is active, so a disabled or removed plugin leaves the schedule
 * exactly as the code declares it.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-governance
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
final class Registrar
{
    private const BOUND_FLAG = 'gp247.scheduled-tasks.registered';

    /**
     * Register the governed schedule, the recorders, the purge command and its schedule.
     *
     * @param Application $app
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public static function register(Application $app): void
    {
        if ($app->bound(self::BOUND_FLAG)) {
            return;
        }
        $app->instance(self::BOUND_FLAG, true);

        $app->singleton(TaskSynchronizer::class);
        $app->singleton(ScheduleGovernor::class);
        $app->singleton(ScheduledRunRecorder::class);
        $app->singleton(ExternalRunRecorder::class);
        $app->singleton(WorkerHeartbeat::class);

        // WHY extend(): applies whether Schedule is resolved later (normal boot order)
        // or already was — Container::extend() re-wraps an existing instance too.
        $app->extend(Schedule::class, static fn (Schedule $schedule) => GovernedSchedule::fromBase($schedule));

        $schedulePurge = static function (Schedule $schedule): void {
            $schedule->command('scheduled-tasks:purge-runs --execute')->dailyAt('03:45');
        };
        $app->afterResolving(Schedule::class, $schedulePurge);
        if ($app->resolved(Schedule::class)) {
            $schedulePurge($app->make(Schedule::class));
        }

        Event::listen(ScheduledTaskStarting::class, [ScheduledRunRecorder::class, 'starting']);
        Event::listen(ScheduledTaskSkipped::class, [ScheduledRunRecorder::class, 'skipped']);
        Event::listen(ScheduledTaskFinished::class, [ScheduledRunRecorder::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [ScheduledRunRecorder::class, 'failed']);
        Event::listen(ScheduledBackgroundTaskFinished::class, [ScheduledRunRecorder::class, 'backgroundFinished']);
        Event::listen(CommandStarting::class, [ExternalRunRecorder::class, 'starting']);
        Event::listen(CommandFinished::class, [ExternalRunRecorder::class, 'finished']);
        Event::listen(Looping::class, [WorkerHeartbeat::class, 'record']);

        if ($app->runningInConsole()) {
            // Same mechanism as ServiceProvider::commands(), usable from a static context.
            \Illuminate\Console\Application::starting(static function ($artisan): void {
                $artisan->resolve(PurgeRuns::class);
            });
        }

        self::registerNoticeLine($app);
    }

    /**
     * Expose the bell message under a key core's notice renderer can resolve.
     *
     * WHY: core stores notice content as "key::param__value" and splits on "::",
     * so the plugin's namespaced lang key cannot be used there directly.
     *
     * @param Application $app
     * @return void
     */
    private static function registerNoticeLine(Application $app): void
    {
        $translator = $app['translator'];
        foreach (glob(dirname(__DIR__) . '/Lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locale = basename($dir);
            $lines = is_file($dir . '/lang.php') ? (array) require $dir . '/lang.php' : [];
            if (isset($lines['notice_discovered'])) {
                $translator->addLines([TaskSynchronizer::NOTICE_LINE => $lines['notice_discovered']], $locale);
            }
        }
    }
}
