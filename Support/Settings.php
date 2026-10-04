<?php

namespace App\GP247\Plugins\ScheduledTasks\Support;

/**
 * Effective plugin settings: the site's `config/scheduled-tasks.php` over the
 * plugin's own config.php defaults.
 *
 * WHY not the plugin config namespace alone: config.php is replaced on every plugin
 * update, so a value a site owner changed there would silently reset. A site-level
 * config file is never touched by the updater.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-run-history
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    /**
     * Read one setting.
     *
     * @param string $key Setting name (see config.php).
     * @return mixed The site override when present, else the plugin default.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public static function get(string $key): mixed
    {
        $site = config('scheduled-tasks.' . $key);
        if ($site !== null) {
            return $site;
        }

        // WHY: automated test runs must not write "outside run" rows for every
        // artisan call the suite makes, unless a test opts in explicitly.
        if ($key === 'record_external' && app()->runningUnitTests()) {
            return false;
        }

        return self::defaults()[$key] ?? null;
    }

    /**
     * Read an integer setting with a lower bound.
     *
     * @param string $key Setting name.
     * @param int    $min Smallest accepted value.
     * @return int
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-history
     */
    public static function int(string $key, int $min = 1): int
    {
        return max($min, (int) self::get($key));
    }

    /**
     * Extra "Run now" arguments declared for a task, or '' when none.
     *
     * @param string $taskKey Task identity.
     * @return string
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-run-now
     */
    public static function runNowArgs(string $taskKey): string
    {
        $map = self::get('run_now_args');

        return is_array($map) && isset($map[$taskKey]) && is_string($map[$taskKey]) ? trim($map[$taskKey]) : '';
    }

    /**
     * Defaults shipped with the plugin.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return self::$defaults ??= (array) require dirname(__DIR__) . '/config.php';
    }
}
