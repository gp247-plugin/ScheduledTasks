<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Services\SchedulerHealth;
use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;

/**
 * Applies the owner's configuration to the events of the schedule.
 *
 * Every failure is swallowed and reported: the events then keep exactly what the
 * code declared (NFR-AVAIL-scheduled-tasks-fail-open).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-governance
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
class ScheduleGovernor
{
    public const UNIDENTIFIED_KEY = 'scheduled-tasks:unidentified';
    public const COMMAND_NAMES_KEY = 'scheduled-tasks:scheduled-commands';
    public const CHILD_ENV = 'SCHEDULED_TASKS_CHILD';
    public const MUTEX_PREFIX = 'gp247-scheduled-task:';

    /** @var \WeakMap<Event, array<string, mixed>> What the code declared + what this pass decided, per event. */
    private \WeakMap $meta;

    /** @var array<string, array{enabled: bool, manual: bool}> Decision of the current pass, per task key. */
    private array $state = [];

    /**
     * @param TaskSynchronizer $synchronizer
     */
    public function __construct(private readonly TaskSynchronizer $synchronizer)
    {
        $this->meta = new \WeakMap();
    }

    /**
     * The container-bound governor.
     *
     * @return self
     */
    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * Apply the configuration to the given events.
     *
     * @param list<Event> $events Events of the schedule (modified in place).
     * @param bool        $tick   True for a real scheduler pass (heartbeat, abandoned runs, child marker).
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public function apply(array $events, bool $tick): void
    {
        if ($tick) {
            $this->markChildProcesses();
            $this->beat();
        }

        $this->state = [];

        try {
            $identified = $this->identify($events);
            $rows = $this->synchronizer->sync($identified);
            if ($tick) {
                $this->synchronizer->markAbandoned();
            }
            foreach ($identified as $item) {
                $this->configure($item['event'], $item['key'], $rows[$item['key']] ?? null);
            }
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Whether the event of a task may run in this pass (the filter added to each event).
     *
     * @param string $taskKey
     * @return bool True when enabled, flagged for run-now, or unknown (fail-open).
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-toggle
     */
    public function allows(string $taskKey): bool
    {
        $state = $this->state[$taskKey] ?? null;

        return $state === null || $state['enabled'] || $state['manual'];
    }

    /**
     * What the recorder needs to know about an event (task id, trigger, output file…).
     *
     * @param Event $event
     * @return array<string, mixed>|null Null for events the plugin does not manage.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function metaFor(Event $event): ?array
    {
        $meta = $this->meta[$event] ?? null;

        return ($meta !== null && !empty($meta['task_id'])) ? $meta : null;
    }

    /**
     * Restore every event to its declared state and compute its task key.
     *
     * @param list<Event> $events
     * @return list<array{event: Event, key: string, identity: TaskIdentity}>
     */
    private function identify(array $events): array
    {
        $seen = [];
        $identified = [];
        $unidentified = [];
        $commandNames = [];

        foreach ($events as $event) {
            $meta = $this->meta[$event] ?? null;
            if ($meta === null) {
                $meta = ['expression' => $event->expression, 'command' => $event->command, 'configured' => false];
            } else {
                // WHY: a previous pass of this process may have changed them.
                $event->expression = $meta['expression'];
                $event->command = $meta['command'];
            }

            $identity = TaskIdentity::fromEvent($event, $meta['command']);
            if ($identity === null) {
                $unidentified[] = $event->getSummaryForDisplay() ?: $event->expression;
                $this->meta[$event] = $meta;
                continue;
            }

            $count = $seen[$identity->taskKey] = ($seen[$identity->taskKey] ?? 0) + 1;
            $key = $count === 1 ? $identity->taskKey : mb_substr($identity->taskKey, 0, 248) . '#' . $count;

            $this->meta[$event] = array_merge($meta, ['key' => $key]);
            $identified[] = ['event' => $event, 'key' => $key, 'identity' => $identity];
            if ($identity->kind === TaskIdentity::KIND_COMMAND) {
                $commandNames[] = strtok($identity->command, ' ');
            }
        }

        Cache::put(self::UNIDENTIFIED_KEY, $unidentified, now()->addDay());
        // WHY: lets the outside-run recorder recognise a scheduled command run by hand
        // (any package) without a database query for every artisan call.
        Cache::put(self::COMMAND_NAMES_KEY, array_values(array_unique($commandNames)), now()->addDay());

        return $identified;
    }

    /**
     * Decide expression / command / filter / lock / output for one event.
     *
     * @param Event              $event
     * @param string             $key
     * @param ScheduledTask|null $task
     * @return void
     */
    private function configure(Event $event, string $key, ?ScheduledTask $task): void
    {
        $meta = $this->meta[$event];
        $manual = $task !== null && $task->isControllable() && $task->run_now_requested_at !== null;

        if ($task !== null) {
            $this->applyCron($event, $task);
            if ($manual) {
                // WHY: due in this pass only; the flag is cleared after the run.
                $event->expression = '* * * * *';
                $args = Settings::runNowArgs($key);
                if ($args !== '' && !$event instanceof CallbackEvent) {
                    $event->command = $meta['command'] . ' ' . $args;
                }
            }
            $this->state[$key] = ['enabled' => !$task->isControllable() || (bool) $task->is_enabled, 'manual' => $manual];
        }

        if (!$meta['configured']) {
            $event->when(fn () => $this->allows($key));
            if (!$event->withoutOverlapping) {
                $event->withoutOverlapping();
            }
            // WHY: a lock named after the task survives cron changes and covers two
            // overlapping schedule:run processes (cron on shared hosting).
            $event->createMutexNameUsing(self::MUTEX_PREFIX . sha1($key));
            $meta['output'] = $this->captureOutput($event, $key);
            $meta['configured'] = true;
        }

        $this->meta[$event] = array_merge($meta, [
            'task_id' => $task?->id,
            'trigger' => $manual ? 'manual' : 'schedule',
            'requested_by' => $manual ? $task->run_now_requested_by : null,
        ]);
    }

    /**
     * Use the admin cron when it is valid and the task is not sub-minute.
     *
     * @param Event         $event
     * @param ScheduledTask $task
     * @return void
     */
    private function applyCron(Event $event, ScheduledTask $task): void
    {
        if (!$task->override_cron || $event->isRepeatable()) {
            return;
        }

        if ((new CronSchedule($task->override_cron))->isValid()) {
            $event->expression = trim($task->override_cron);

            return;
        }

        SafeReport::throwable(new \UnexpectedValueException(sprintf(
            'Scheduled task "%s" has an invalid cron override "%s"; using the code schedule.',
            $task->task_key,
            $task->override_cron
        )));
    }

    /**
     * Send command output to a per-task file unless the author chose a destination.
     *
     * @param Event  $event
     * @param string $key
     * @return string|null File holding the output of the last run.
     */
    private function captureOutput(Event $event, string $key): ?string
    {
        if ($event instanceof CallbackEvent) {
            return null;
        }

        if ($event->output !== $event->getDefaultOutput()) {
            return $event->output;
        }

        $directory = storage_path('logs/scheduled-tasks');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $path = $directory . DIRECTORY_SEPARATOR . sha1($key) . '.log';
        $event->sendOutputTo($path);

        return $path;
    }

    /**
     * Record that the scheduler is alive.
     *
     * @return void
     */
    private function beat(): void
    {
        try {
            app(SchedulerHealth::class)->record();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Mark processes started by this scheduler pass so they are not logged as outside runs.
     *
     * @return void
     */
    private function markChildProcesses(): void
    {
        if (!app()->runningUnitTests()) {
            putenv(self::CHILD_ENV . '=1');
        }
    }

}
