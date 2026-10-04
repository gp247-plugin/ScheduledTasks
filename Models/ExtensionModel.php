<?php

namespace App\GP247\Plugins\ScheduledTasks\Models;

use Illuminate\Support\Facades\Schema;

/**
 * Creates and removes the plugin's two tables.
 *
 * Creation is re-entrant (guarded by hasTable) so install() and update() can both run
 * it on sites in any state.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-governance
 */
class ExtensionModel
{
    public const TABLE_TASK = 'scheduled_task';
    public const TABLE_RUN = 'scheduled_task_run';
    public const TABLE_HEARTBEAT = 'scheduled_task_heartbeat';

    /**
     * Create the task and run-history tables when missing.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public function installExtension()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);

        if (!$schema->hasTable(GP247_DB_PREFIX . self::TABLE_TASK)) {
            $schema->create(GP247_DB_PREFIX . self::TABLE_TASK, function ($table) {
                $table->increments('id');
                $table->string('task_key', 255)->unique();
                $table->string('source', 20)->default('schedule');
                $table->string('kind', 20)->default('command');
                $table->text('command');
                $table->string('description', 255)->nullable();
                $table->string('code_cron', 100)->nullable();
                $table->string('timezone', 64)->nullable();
                $table->boolean('is_repeatable')->default(false);
                $table->string('override_cron', 100)->nullable();
                $table->string('override_base_cron', 100)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->dateTime('run_now_requested_at')->nullable();
                $table->char('run_now_requested_by', 36)->nullable();
                $table->dateTime('discovered_at');
                $table->dateTime('last_seen_at')->nullable();
                $table->dateTime('acknowledged_at')->nullable();
                $table->char('acknowledged_by', 36)->nullable();
                $table->char('updated_by', 36)->nullable();
                $table->timestamps();
                $table->index('source');
            });
        }

        if (!$schema->hasTable(GP247_DB_PREFIX . self::TABLE_RUN)) {
            $schema->create(GP247_DB_PREFIX . self::TABLE_RUN, function ($table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('scheduled_task_id');
                $table->string('trigger', 20);
                $table->char('triggered_by', 36)->nullable();
                $table->string('status', 20);
                $table->dateTime('started_at');
                $table->dateTime('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->smallInteger('exit_code')->nullable();
                $table->string('arguments', 1000)->nullable();
                $table->text('output')->nullable();
                $table->text('error_message')->nullable();
                $table->string('host', 100)->nullable();
                $table->unsignedInteger('pid')->nullable();
                $table->string('os_user', 64)->nullable();
                $table->index(['scheduled_task_id', 'started_at']);
                $table->index(['status', 'started_at']);
                $table->index('started_at');
            });
        }

        // WHY in the database, not the cache: cache:clear wipes the cache and the web
        // and CLI processes may not share one — either made the screen claim the
        // scheduler had never run.
        if (!$schema->hasTable(GP247_DB_PREFIX . self::TABLE_HEARTBEAT)) {
            $schema->create(GP247_DB_PREFIX . self::TABLE_HEARTBEAT, function ($table) {
                $table->increments('id');
                $table->string('host', 100)->unique();
                $table->dateTime('last_tick_at');
                $table->text('recent_ticks')->nullable();
            });
        }
    }

    /**
     * Drop the plugin tables (run history, scheduler heartbeats and the owner's schedule settings).
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function uninstallExtension()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $schema->dropIfExists(GP247_DB_PREFIX . self::TABLE_HEARTBEAT);
        $schema->dropIfExists(GP247_DB_PREFIX . self::TABLE_RUN);
        $schema->dropIfExists(GP247_DB_PREFIX . self::TABLE_TASK);
    }
}
