<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTask;
use App\GP247\Plugins\ScheduledTasks\Models\ScheduledTaskRun;
use App\GP247\Plugins\ScheduledTasks\Support\Settings;
use Illuminate\Console\Scheduling\Event;

/**
 * Keeps the task table in step with what the schedule holds, in a fixed, small
 * number of queries per pass (NFR-PERF-scheduled-tasks-tick-overhead): one read,
 * writes only for new / changed tasks, one bulk "last seen" update.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
 */
class TaskSynchronizer
{
    /** Translation line used in the admin bell (core splits notice content on "::"). */
    public const NOTICE_LINE = 'gp247_scheduled_tasks.notice_discovered';

    /**
     * Create / refresh the rows of the identified events.
     *
     * @param list<array{event: Event, key: string, identity: TaskIdentity}> $identified
     * @return array<string, ScheduledTask> Rows keyed by task key.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public function sync(array $identified): array
    {
        $keys = array_column($identified, 'key');
        $rows = ScheduledTask::query()
            ->where('source', ScheduledTask::SOURCE_SCHEDULE)
            ->when($keys !== [], fn ($q) => $q->orWhereIn('task_key', $keys))
            ->get()
            ->keyBy('task_key');

        // WHY: right after install every task is "new"; flagging and notifying all of
        // them would bury the one that matters later.
        $firstDiscovery = $rows->where('source', ScheduledTask::SOURCE_SCHEDULE)->isEmpty();
        $now = now();

        foreach ($identified as $item) {
            $attributes = $this->attributesOf($item['event'], $item['identity']);
            $row = $rows->get($item['key']);

            if ($row === null) {
                $row = ScheduledTask::create($attributes + [
                    'task_key' => $item['key'],
                    'is_enabled' => true,
                    'discovered_at' => $now,
                    'last_seen_at' => $now,
                    'acknowledged_at' => $firstDiscovery ? $now : null,
                ]);
                $rows->put($item['key'], $row);
                if (!$firstDiscovery) {
                    $this->notifyDiscovered($row);
                }
                continue;
            }

            $row->fill($attributes);
            if ($row->isDirty()) {
                $row->save();
            }
        }

        if ($keys !== []) {
            ScheduledTask::query()->whereIn('task_key', $keys)->update(['last_seen_at' => $now]);
            foreach ($keys as $key) {
                $rows->get($key)?->setAttribute('last_seen_at', $now);
            }
        }

        return $rows->all();
    }

    /**
     * Flag runs left "running" past the threshold (crashed or killed processes).
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public function markAbandoned(): void
    {
        ScheduledTaskRun::query()
            ->where('status', ScheduledTaskRun::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(Settings::int('stale_after_minutes')))
            ->update(['status' => ScheduledTaskRun::STATUS_ABANDONED]);
    }

    /**
     * Columns describing the event as the code declares it.
     *
     * @param Event        $event
     * @param TaskIdentity $identity
     * @return array<string, mixed>
     */
    private function attributesOf(Event $event, TaskIdentity $identity): array
    {
        $timezone = $event->timezone;

        return [
            'source' => ScheduledTask::SOURCE_SCHEDULE,
            'kind' => $identity->kind,
            'command' => $identity->command,
            'description' => $event->description ? mb_substr((string) $event->description, 0, 255) : null,
            'code_cron' => $event->expression,
            'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : ($timezone ?: null),
            'is_repeatable' => $event->isRepeatable(),
        ];
    }

    /**
     * Ring the admin bell for a task that appeared after install.
     *
     * @param ScheduledTask $task
     * @return void
     */
    private function notifyDiscovered(ScheduledTask $task): void
    {
        if (!function_exists('gp247_notice_add')) {
            return;
        }

        // WHY: gp247_content_render() splits on "::" and "__"; keep them out of the value.
        $label = str_replace(['::', '__'], [': ', '_'], $task->task_key);
        gp247_notice_add('scheduled_task_discovered', (string) $task->id, self::NOTICE_LINE . '::task__' . $label);
    }
}
