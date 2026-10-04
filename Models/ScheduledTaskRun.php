<?php

namespace App\GP247\Plugins\ScheduledTasks\Models;

use GP247\Core\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of a task. Immutable for the business: only the recorders move a
 * row from running to its final status; only the purge command deletes rows.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 */
class ScheduledTaskRun extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_EXTERNAL = 'external';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ABANDONED = 'abandoned';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_ABANDONED,
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
        'pid' => 'integer',
    ];

    /**
     * Bind the model to the GP247 connection and prefixed table.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->connection = GP247_DB_CONNECTION;
        $this->table = GP247_DB_PREFIX . ExtensionModel::TABLE_RUN;
        parent::__construct($attributes);
    }

    /**
     * Drop the quotes the console adds around single tokens ("gp247:update" → gp247:update).
     *
     * WHY: Symfony's input string quotes every token that is not plain [\w-]+, so a
     * command name with ":" is stored quoted; quotes around a token containing spaces
     * are meaningful and kept.
     *
     * @param string $arguments
     * @return string
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-external-runs
     */
    public static function unquoteArguments(string $arguments): string
    {
        return (string) preg_replace(['/"([^"\s]*)"/', "/'([^'\\s]*)'/"], '$1', $arguments);
    }

    /**
     * Arguments as the user typed them (also for rows stored before unquoting existed).
     *
     * @return string
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-external-runs
     */
    public function displayArguments(): string
    {
        return self::unquoteArguments((string) $this->arguments);
    }

    /**
     * The task this run belongs to.
     *
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }

    /**
     * The admin who asked for this run (run-now only).
     *
     * @return BelongsTo
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'triggered_by');
    }
}
