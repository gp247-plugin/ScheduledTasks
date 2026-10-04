<?php

namespace App\GP247\Plugins\ScheduledTasks\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Last scheduler passes seen on one host (one row per machine running schedule:run).
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-worker-health
 */
class SchedulerHeartbeat extends Model
{
    /** How many recent pass times are kept to measure the interval. */
    public const KEEP_TICKS = 6;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'last_tick_at' => 'datetime',
    ];

    /**
     * Bind the model to the GP247 connection and prefixed table.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->connection = GP247_DB_CONNECTION;
        $this->table = GP247_DB_PREFIX . ExtensionModel::TABLE_HEARTBEAT;
        parent::__construct($attributes);
    }

    /**
     * Recent pass times as unix timestamps, oldest first.
     *
     * @return list<int>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-worker-health
     */
    public function ticks(): array
    {
        $ticks = json_decode((string) $this->recent_ticks, true);

        return is_array($ticks) ? array_values(array_map('intval', array_filter($ticks, 'is_numeric'))) : [];
    }
}
