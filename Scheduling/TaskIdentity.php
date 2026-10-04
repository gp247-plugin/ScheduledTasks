<?php

namespace App\GP247\Plugins\ScheduledTasks\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Stable identity of a schedule event across processes and deploys.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
 */
final class TaskIdentity
{
    public const KIND_COMMAND = 'command';
    public const KIND_CALLBACK = 'callback';
    public const KIND_EXEC = 'exec';

    /**
     * @param string $taskKey Identity (before duplicate suffixing).
     * @param string $kind    command|callback|exec.
     * @param string $command Human-readable command.
     */
    public function __construct(
        public readonly string $taskKey,
        public readonly string $kind,
        public readonly string $command,
    ) {
    }

    /**
     * Build the identity of an event, or null for a closure without a name.
     *
     * @param Event       $event   Schedule event.
     * @param string|null $command The event's original command (before the plugin appended run-now args).
     * @return self|null
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public static function fromEvent(Event $event, ?string $command = null): ?self
    {
        if ($event instanceof CallbackEvent) {
            $name = trim((string) $event->description);

            return $name === '' ? null : new self(mb_substr($name, 0, 255), self::KIND_CALLBACK, $name);
        }

        $raw = trim((string) ($command ?? $event->command));
        if ($raw === '') {
            return null;
        }

        [$normalized, $isArtisan] = self::split($raw, Application::phpBinary(), Application::artisanBinary());
        $kind = $isArtisan ? self::KIND_COMMAND : self::KIND_EXEC;

        return new self(mb_substr($normalized, 0, 255), $kind, $normalized);
    }

    /**
     * Strip the PHP binary + artisan prefix (quoted or not) and any output redirect.
     *
     * @param string $raw     Event command string.
     * @param string $php     PHP binary as Laravel formats it (often quoted).
     * @param string $artisan Artisan binary as Laravel formats it (often quoted).
     * @return string The artisan command + arguments, or the raw command when it is not artisan.
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-auto-discovery
     */
    public static function normalizeCommand(string $raw, string $php, string $artisan): string
    {
        return self::split($raw, $php, $artisan)[0];
    }

    /**
     * Normalise a command and tell whether it was an artisan call.
     *
     * @param string $raw     Event command string.
     * @param string $php     PHP binary.
     * @param string $artisan Artisan binary.
     * @return array{0: string, 1: bool}
     */
    private static function split(string $raw, string $php, string $artisan): array
    {
        $command = trim(preg_replace('/\s+\d?>{1,2}\s*("[^"]*"|\'[^\']*\'|\S+)(\s+2>&1)?\s*$/', '', $raw) ?? $raw);

        $phpForms = array_unique([$php, trim($php, '"\'')]);
        $artisanForms = array_unique([$artisan, trim($artisan, '"\''), 'artisan', '"artisan"', "'artisan'"]);

        foreach ($phpForms as $p) {
            foreach ($artisanForms as $a) {
                $prefix = $p . ' ' . $a . ' ';
                if ($p !== '' && str_starts_with($command, $prefix)) {
                    return [trim(substr($command, strlen($prefix))), true];
                }
            }
        }

        return [$command, false];
    }
}
