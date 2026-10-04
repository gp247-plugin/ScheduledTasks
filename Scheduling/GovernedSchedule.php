<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * The Laravel schedule, with the owner's configuration applied just before the
 * framework looks at the events.
 *
 * WHY a subclass swapped in with Container::extend(): ScheduleRunCommand selects due
 * tasks through dueEvents(), and schedule:list / schedule:finish / schedule:test read
 * events(); overriding both is the one place every scheduler entry point passes
 * through, whoever registered the events and wherever.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-governance
 * @aidlc-adr ADR-plugin-scheduled-tasks-schedule-governance
 */
class GovernedSchedule extends Schedule
{
    /**
     * Copy a framework-built schedule (and any events already on it) into a governed one.
     *
     * WHY reflection: copying every non-static property keeps working when a Laravel
     * release adds or renames internals, instead of hard-coding today's list.
     *
     * @param Schedule $base The instance the framework resolved.
     * @return static
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public static function fromBase(Schedule $base): static
    {
        if ($base instanceof static) {
            return $base;
        }

        $governed = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $class = new \ReflectionClass(Schedule::class);
        do {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if ($property->isInitialized($base)) {
                    $property->setValue($governed, $property->getValue($base));
                }
            }
        } while ($class = $class->getParentClass());

        return $governed;
    }

    /**
     * Apply the configuration (with the per-tick housekeeping), then let the framework filter.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return \Illuminate\Support\Collection
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public function dueEvents($app)
    {
        ScheduleGovernor::instance()->apply($this->events, true);

        return parent::dueEvents($app);
    }

    /**
     * Apply the configuration so listings and background-finish see the effective state.
     *
     * @return \Illuminate\Console\Scheduling\Event[]
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-governance
     */
    public function events()
    {
        ScheduleGovernor::instance()->apply($this->events, false);

        return parent::events();
    }
}
