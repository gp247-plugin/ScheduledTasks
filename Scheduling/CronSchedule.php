<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * A five-field cron expression with the helpers the screens need.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-cron-config
 */
final class CronSchedule
{
    /** Upper bound of runs counted when comparing densities (one week of minutes). */
    private const COUNT_CAP = 10080;

    /**
     * @param string $expression Raw cron string.
     */
    public function __construct(private readonly string $expression)
    {
    }

    /**
     * Exactly five fields and accepted by the cron parser (no @aliases).
     *
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function isValid(): bool
    {
        $trimmed = trim($this->expression);
        if ($trimmed === '' || count(preg_split('/\s+/', $trimmed)) !== 5) {
            return false;
        }

        try {
            return CronExpression::isValidExpression($trimmed);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The next due times.
     *
     * @param int                     $count    How many runs.
     * @param string                  $timezone Timezone the expression is read in.
     * @param \DateTimeInterface|null $from     Start point (default now).
     * @return list<CarbonImmutable> Empty when the expression is invalid.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function nextRuns(int $count, string $timezone, ?\DateTimeInterface $from = null): array
    {
        if (!$this->isValid()) {
            return [];
        }

        $from = CarbonImmutable::instance($from ?? now())->setTimezone($timezone);
        $dates = (new CronExpression(trim($this->expression)))->getMultipleRunDates($count, $from, false, false, $timezone);

        return array_map(static fn ($date) => CarbonImmutable::instance($date)->setTimezone($timezone), $dates);
    }

    /**
     * Runs in one day (a Monday, UTC) — used to show how dense a schedule is.
     *
     * @return int
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function runsPerDay(): int
    {
        return $this->countRuns(1);
    }

    /**
     * Whether this schedule fires more often than another over a week.
     *
     * @param string|null $other Expression to compare with.
     * @return bool
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-cron-config
     */
    public function isDenserThan(?string $other): bool
    {
        if ($other === null || !(new self($other))->isValid()) {
            return false;
        }

        return $this->countRuns(7) > (new self($other))->countRuns(7);
    }

    /**
     * Count runs in a window of whole days starting Monday 2026-01-05 00:00 UTC.
     *
     * @param int $days Window length.
     * @return int Capped at COUNT_CAP.
     */
    private function countRuns(int $days): int
    {
        if (!$this->isValid()) {
            return 0;
        }

        $cron = new CronExpression(trim($this->expression));
        $start = new \DateTimeImmutable('2026-01-05 00:00:00', new \DateTimeZone('UTC'));
        $end = $start->modify('+' . $days . ' days');
        $count = 0;
        $cursor = $start;
        $allowCurrent = true;

        while ($count < self::COUNT_CAP) {
            $next = \DateTimeImmutable::createFromMutable($cron->getNextRunDate($cursor, 0, $allowCurrent, 'UTC'));
            if ($next >= $end) {
                break;
            }
            $count++;
            $cursor = $next;
            $allowCurrent = false;
        }

        return $count;
    }
}
