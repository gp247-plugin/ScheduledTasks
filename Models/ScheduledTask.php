<?php

namespace App\GP247\Plugins\ScheduledTasks\Models;

use App\GP247\Plugins\ScheduledTasks\Scheduling\CronSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A task known to the plugin: an event of the Laravel schedule, or an artisan
 * command that once ran outside of it.
 *
 * The task key and command are only ever written by the scheduler / recorders,
 * never from a web request (NFR-SEC-scheduled-tasks-command-whitelist).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-list-status
 */
class ScheduledTask extends Model
{
    public const SOURCE_SCHEDULE = 'schedule';
    public const SOURCE_EXTERNAL = 'external';

    /** Minutes without being seen in the schedule before a task counts as gone. */
    public const GONE_AFTER_MINUTES = 10;

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_repeatable' => 'boolean',
        'run_now_requested_at' => 'datetime',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /**
     * Bind the model to the GP247 connection and prefixed table.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->connection = GP247_DB_CONNECTION;
        $this->table = GP247_DB_PREFIX . ExtensionModel::TABLE_TASK;
        parent::__construct($attributes);
    }

    /**
     * Run history of this task.
     *
     * @return HasMany
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class, 'scheduled_task_id');
    }

    /**
     * The most recent run, whatever started it.
     *
     * @return HasOne
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ScheduledTaskRun::class, 'scheduled_task_id')->latestOfMany();
    }

    /**
     * Not yet acknowledged by someone allowed to configure tasks.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function isNew(): bool
    {
        return $this->acknowledged_at === null;
    }

    /**
     * A schedule task the code no longer registers.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function isGone(): bool
    {
        return $this->source === self::SOURCE_SCHEDULE
            && $this->last_seen_at !== null
            && $this->last_seen_at->lt(now()->subMinutes(self::GONE_AFTER_MINUTES));
    }

    /**
     * Whether on/off and run-now apply (schedule tasks still in the code).
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function isControllable(): bool
    {
        return $this->source === self::SOURCE_SCHEDULE && !$this->isGone();
    }

    /**
     * Whether the admin may override the cron (never for sub-minute tasks).
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function canChangeCron(): bool
    {
        return $this->isControllable() && !$this->is_repeatable;
    }

    /**
     * The cron the scheduler actually uses.
     *
     * @return string|null
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public function effectiveCron(): ?string
    {
        if ($this->override_cron && !$this->is_repeatable && (new CronSchedule($this->override_cron))->isValid()) {
            return $this->override_cron;
        }

        return $this->code_cron;
    }

    /**
     * The admin set a schedule that differs from the code schedule.
     *
     * WHY not just "override_cron is set": an override equal to the code schedule
     * changes nothing and must not be shown as an admin schedule.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function hasOverride(): bool
    {
        return $this->override_cron !== null
            && self::normalizeCron($this->override_cron) !== self::normalizeCron((string) $this->code_cron);
    }

    /**
     * Collapse whitespace so "0  3 * * *" and "0 3 * * *" compare equal.
     *
     * @param string $cron
     * @return string
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public static function normalizeCron(string $cron): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($cron));
    }

    /**
     * The developer moved the code schedule after the admin set an override.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function codeCronChanged(): bool
    {
        return (bool) $this->override_cron
            && $this->override_base_cron !== null
            && $this->override_base_cron !== $this->code_cron;
    }

    /**
     * A run-now flag is waiting for the scheduler.
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-now
     */
    public function isRunNowPending(): bool
    {
        return $this->run_now_requested_at !== null;
    }

    /**
     * Next due time in the task's own timezone, or null when unknown.
     *
     * @return CarbonImmutable|null
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-list-status
     */
    public function nextRunAt(): ?CarbonImmutable
    {
        $cron = $this->effectiveCron();
        if (!$cron || !$this->isControllable()) {
            return null;
        }

        return (new CronSchedule($cron))->nextRuns(1, $this->timezone ?: (string) config('app.timezone'))[0] ?? null;
    }
}
