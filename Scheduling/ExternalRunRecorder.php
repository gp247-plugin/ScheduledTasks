<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Support\SafeReport;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Records artisan commands that run outside the scheduler (own crontab, by hand),
 * using an allowlist: GP247 / App classes, closure commands of routes/console.php,
 * commands that are on the schedule (any package), and the site's
 * `external_commands` patterns. Other framework / package commands are ignored.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-external-runs
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
class ExternalRunRecorder
{
    private const OWN_NAMESPACES = ['GP247\\', 'App\\'];

    /** @var array<int, int> Run row per command input object. */
    private array $current = [];

    /**
     * Open an "external" run row for an eligible command.
     *
     * @param CommandStarting $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-external-runs
     */
    public function starting(CommandStarting $event): void
    {
        try {
            if (!$this->eligible($event->command)) {
                return;
            }

            $name = (string) $event->command;
            $arguments = ScheduledTaskRun::unquoteArguments((string) $event->input);
            $task = $this->resolveTask($name, $arguments);

            $run = ScheduledTaskRun::create([
                'scheduled_task_id' => $task->id,
                'trigger' => ScheduledTaskRun::TRIGGER_EXTERNAL,
                'status' => ScheduledTaskRun::STATUS_RUNNING,
                'started_at' => now(),
                'arguments' => mb_substr($arguments, 0, 1000),
            ] + RunHost::describe());
            $this->current[spl_object_id($event->input)] = $run->id;
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * Close the row with the exit code.
     *
     * @param CommandFinished $event
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-external-runs
     */
    public function finished(CommandFinished $event): void
    {
        $key = spl_object_id($event->input);
        $id = $this->current[$key] ?? null;
        if ($id === null) {
            return;
        }
        unset($this->current[$key]);

        try {
            $run = ScheduledTaskRun::find($id);
            if ($run === null) {
                return;
            }
            $run->fill([
                'status' => $event->exitCode === 0 ? ScheduledTaskRun::STATUS_SUCCESS : ScheduledTaskRun::STATUS_FAILED,
                'exit_code' => $event->exitCode,
                'finished_at' => now(),
                'duration_ms' => max(0, (int) $run->started_at->diffInMilliseconds(now())),
            ])->save();
        } catch (\Throwable $e) {
            SafeReport::throwable($e);
        }
    }

    /**
     * The task a hand run belongs to.
     *
     * Order: the scheduled task with exactly this command line; else the only scheduled
     * task of this command name; else the outside-run task named after the command
     * (created on first use).
     *
     * @param string $name      Command name.
     * @param string $arguments Command line as typed.
     * @return ScheduledTask
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-external-runs
     */
    private function resolveTask(string $name, string $arguments): ScheduledTask
    {
        $candidates = ScheduledTask::query()
            ->where('source', ScheduledTask::SOURCE_SCHEDULE)
            ->where('kind', TaskIdentity::KIND_COMMAND)
            ->where(fn ($q) => $q->where('command', $name)->orWhere('command', 'like', $name . ' %'))
            ->get()
            // WHY: "_" and "%" in a command name are LIKE wildcards — confirm the name exactly.
            ->filter(fn (ScheduledTask $task) => strtok($task->command, ' ') === $name)
            ->values();

        $typed = ScheduledTask::normalizeCron($arguments);
        $exact = $candidates->first(fn (ScheduledTask $task) => ScheduledTask::normalizeCron($task->command) === $typed);
        if ($exact !== null) {
            return $exact;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return ScheduledTask::query()->where('task_key', $name)->first()
            ?? ScheduledTask::create([
                'task_key' => mb_substr($name, 0, 255),
                'source' => ScheduledTask::SOURCE_EXTERNAL,
                'kind' => TaskIdentity::KIND_COMMAND,
                'command' => $name,
                'discovered_at' => now(),
            ]);
    }

    /**
     * Recording is on, not a child of the scheduler, and the command is on the allowlist.
     *
     * @param string|null $name
     * @return bool
     */
    private function eligible(?string $name): bool
    {
        if ($name === null || $name === '' || !Settings::get('record_external') || getenv(ScheduleGovernor::CHILD_ENV)) {
            return false;
        }

        $command = app(Kernel::class)->all()[$name] ?? null;
        if ($command === null) {
            return false;
        }

        return $this->isSiteCommand($command)
            || in_array($name, (array) Cache::get(ScheduleGovernor::COMMAND_NAMES_KEY, []), true)
            || $this->matchesConfiguredPattern($name);
    }

    /**
     * A command of GP247, a plugin or the site itself (class or routes/console.php closure).
     *
     * @param object $command
     * @return bool
     */
    private function isSiteCommand(object $command): bool
    {
        if ($command instanceof ClosureCommand) {
            return true;
        }

        $class = get_class($command);
        foreach (self::OWN_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site asked to watch this command (`external_commands` patterns, e.g. "backup:*").
     *
     * @param string $name
     * @return bool
     */
    private function matchesConfiguredPattern(string $name): bool
    {
        $patterns = array_filter((array) Settings::get('external_commands'), 'is_string');

        return $patterns !== [] && Str::is($patterns, $name);
    }
}
