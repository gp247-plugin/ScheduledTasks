> 🌐 **Language:** [🇻🇳 Tiếng Việt](./readme_vi.md) · 🇬🇧 English (current) · [🇯🇵 日本語](./readme_ja.md)

# ScheduledTasks — Scheduled task manager for GP247 / S-Cart

## Introduction
**ScheduledTasks** is a **free** plugin that lets the site owner see and control **every scheduled task** the Laravel
scheduler runs on the site: tasks of the GP247 core (for example sending queued mail), of plugins, and of the site's own code.
This document is for **site owners / operators** (installing, turning the scheduler on, controlling tasks) and for
**developers** (how to add a new task). After reading it you will know how to get the scheduler running in your environment,
how the plugin applies the admin's settings, and why the plugin **only controls tasks that already exist in code** instead of
letting anyone add commands.

> **Important:** for security reasons the screen **does not let anyone add a new console command**, nor type a command name or
> arguments. The plugin only controls tasks that are already scheduled in code — see
> [Why commands cannot be added from the screen](#why-commands-cannot-be-added-from-the-screen).

## Features

| Feature | Description |
|---|---|
| Auto-discovery | Every task registered with `Schedule::…` (anywhere) appears on the screen by itself; new tasks are flagged "Newly discovered" and announced in the admin notification bell |
| Change the schedule | 5-field cron expression, preview of the next 5 runs, warning when denser than the code schedule, one-click restore of the code schedule |
| On / off | Pause a task without touching the code |
| Run now | Flags the task so the scheduler runs it on its next pass (within a minute) — never inside the browser |
| Run history | Start, duration, status, exit code, output tail, error; kept 90 days and pruned automatically |
| Outside the schedule | Records artisan commands run by hand or from an own crontab (see [Commands run outside the schedule](#commands-run-outside-the-schedule)) |
| Health | Is the scheduler running, about how often and on which host; are queue workers alive; says plainly when the site **has no cron** and gives the cron line |
| Queue | Pending jobs (`database` driver), failed jobs in the last 24 hours, retry a failed job (no delete) |
| Audit | Every change is written to the admin log with old → new values |

Every task is protected from overlapping runs. Disable or remove the plugin and every task runs exactly as coded.

## Installation

1. Copy the `ScheduledTasks` folder into the site's `app/GP247/Plugins/` (or upload the ZIP file in admin → **Plugins**).
2. In admin → **Plugins**, find **Scheduled Tasks** and click **Install**.

   If it succeeds, the **System** menu gets a new **Scheduled tasks** entry.
3. Open **System → Scheduled tasks**.

   On first visit you see the tasks currently on the site's schedule. If you see a yellow box "The scheduler has never run on
   this site", continue with [Turn the scheduler on](#turn-the-scheduler-on).

**Requirements:** GP247 core ≥ 3.0 and Livewire (included in the GP247 admin).

## Turn the scheduler on

The **scheduler** (Laravel Scheduler) is the part of the application that checks every minute which tasks are due. It **does
not start by itself** — something has to call `php artisan schedule:run` every minute. Without that nothing runs, and no setting
on the screen has any effect. Pick **one** way that fits your environment:

| Environment | How |
|---|---|
| Shared hosting, Linux VPS | A cron entry every minute (steps below) |
| VPS with supervisor | A supervisor program running `php artisan schedule:work` (Laravel calls `schedule:run` at the start of every minute), as the web server's user (e.g. `www-data`) |
| Docker | A container running `php artisan schedule:work` (or a `schedule:run; sleep 60` loop), with the same code and environment variables as the web container |
| Windows (dev machine) | Task Scheduler repeating every minute, running `php.exe artisan schedule:run` started in the site folder — or keep `php artisan schedule:work` running in a terminal window |

### Adding the cron entry on your hosting (cPanel and similar)

1. Log in to your hosting control panel and open **Cron Jobs** (it may be called "Scheduled Tasks").
2. Choose the frequency **Every minute** (Once Per Minute / `* * * * *`).
3. In the command box, paste the line below — **replace** `/path/to/site` with your site folder. The Scheduled tasks screen
   prints this line with your exact site path, so you can copy it from there:

   ```
   cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
   ```

4. Save, wait 1–2 minutes, then reload the **Scheduled tasks** screen.

   If it works, the yellow/red box disappears and is replaced by a green line "The scheduler is running — last pass …". After a
   few passes the line also shows "about every N min · host: …".

## Why commands cannot be added from the screen

The screen **only controls tasks that are already on the schedule in code** (change the schedule, turn on/off, run now). It does
**not** add new tasks, has **no** field for a command name or arguments, and does **not** offer a pick-list from
`php artisan list`. Why:

- **A console command runs with the full power of the application.** The command list of a GP247 site includes commands that
  wipe all data (`db:wipe`, `migrate:fresh`), remove plugins/templates (`gp247:ext-uninstall`), replace the encryption key and
  break every stored API key (`key:generate`), put the site in maintenance (`down`), or hang the scheduler (`tinker`). Letting the
  web pick or type a command means **anyone who takes over one admin account can run them** — even schedule them to run at
  midnight.
- **Arguments are as dangerous as command names.** A harmless-looking command changes completely with `--force`, `--execute`,
  `--database=…`.
- **The command list changes by itself** whenever a plugin or package is installed, without review — a "forbidden commands"
  list would always be out of date.
- **Code is the single place that decides what runs.** Scheduled tasks are written, reviewed and deployed together with the
  application; the admin only adjusts **when** they run and **whether** they run.

"Run now" does not run anything inside the browser either: it only flags the task for the next scheduler pass. Extra arguments
for "Run now" (when needed) can only be declared in the site's configuration file (`run_now_args`), never taken from the browser.

## How to add a scheduled task (for developers)

Adding a task is a **developer's job, in code**. After deployment the task appears on the screen at the next scheduler pass
("Newly discovered" flag + bell notification); from then on the admin controls it like any other task.

### In the site's own code — file `routes/console.php`

```php
<?php
use Illuminate\Support\Facades\Schedule;

// 1) An artisan command (recommended)
Schedule::command('report:send --daily')->dailyAt('07:00');

// 2) A queued job — identified by its class name
Schedule::job(new \App\Jobs\SyncStock)->everyFifteenMinutes();

// 3) A closure — MUST be named, otherwise it cannot be controlled
Schedule::call(fn () => \App\Support\Cleanup::run())
    ->name('cleanup-temp-files')
    ->hourly();
```

### In a GP247 plugin — file `Provider.php`, inside the "plugin is active" block

```php
if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    // ...
    $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
        $schedule->command('myplugin:sync')->everyThirtyMinutes();
    });
}
```

Keeping it inside the "plugin is active" block means that disabling the plugin also removes the task from the schedule.

### Identity rules — know them before you write

The plugin recognises a task by its **identity**; every admin setting (own schedule, on/off) is attached to that identity:

| Kind | Identity | Example |
|---|---|---|
| Artisan command | Command name + arguments (without the `php`/`artisan` paths) | `report:send --daily` |
| Job | Job class name | `App\Jobs\SyncStock` |
| Closure | Name given with `->name()` | `cleanup-temp-files` |
| System command (`Schedule::exec`) | The command string | `/usr/bin/backup.sh` |

- **Changing the arguments = a new task.** Renaming `report:send --daily` to `report:send --day` makes a different task: the
  old one shows "No longer in the code" (history kept), the new one runs on its code schedule, and the old task's admin settings
  do not carry over.
- **Changing the code schedule keeps the identity.** If the admin set an own schedule, the screen shows "The code schedule
  changed: X → Y".
- The same identity declared twice gets a `#2`, `#3` suffix in declaration order.
- A closure without `->name()` is only counted and reported as "cannot be identified".

### Do and don't

- **Do** put every recurring job on the Laravel schedule instead of an own hosting crontab — scripts outside the schedule
  (bash, curl…) are invisible to the application.
- **No need** to add `withoutOverlapping()`: the plugin protects every task from overlapping. If you set
  `withoutOverlapping(n)`, your lock lifetime `n` is kept.
- `runInBackground()`, `onOneServer()`, `timezone()`, `environments()` keep working as in Laravel; the screen computes the
  "next run" in the task's own timezone.
- Commands that need special arguments for "Run now" (e.g. `--force` to skip their own time window): declare them in the
  site's `run_now_args` (see [Customising](#customising)).

## How it works

### Every scheduler pass

On every `schedule:run` (each minute), **before** Laravel picks the due tasks, the plugin:

1. Records the scheduler heartbeat (time, host).
2. Reads the tasks on the schedule, adds new ones, updates known ones.
3. Applies the admin settings to each task (table below), then lets Laravel run as usual.

The plugin **never changes the code** of a task; it only adjusts the schedule, the run condition and the overlap lock for that pass.

### How admin settings apply

| Setting | Effect |
|---|---|
| **Own schedule (override)** | Effective schedule = the admin's schedule; without one, the code schedule. Applies from the next pass, nothing to restart |
| **Restore the code schedule** | Removes the own schedule; from then on the task follows the code, including later changes by the developer |
| **Turn off** | The task does not run when due. A run in progress is not stopped. Disabled tasks get a highlighted "Disabled" badge and are listed first |
| **Turn on** | Runs again on its effective schedule |
| **Run now** | Flags the task; the next pass runs it **once** (even when disabled), then clears the flag |
| **Seen** | Clears the "Newly discovered" flag |

Every change is written to the admin log: who, when, IP, task, old → new value.

### Run statuses

| Status | Meaning |
|---|---|
| Running | Started, not finished |
| Succeeded / Failed | Finished with exit code 0 / non-zero (or an error — see the details in the history) |
| Skipped (previous run still going) | Due, but the previous run of the same task was still going (overlap lock) |
| Stopped abnormally | "Running" for more than 120 minutes without finishing (process killed); updated if it later really finishes |
| Never ran | The task has no run yet |

### Commands run outside the schedule

Artisan commands run by hand or from an own crontab are recorded in the **Outside the schedule** tab (view only) when they belong
to **one** of these groups:

- commands of GP247, a plugin or the site's own code (including commands written in `routes/console.php`);
- commands that are on the schedule (including other packages' commands) — a hand run is attached to that very task;
- commands matching the `external_commands` list in the site configuration.

Processes started by the scheduler itself are not recorded twice. Other framework/package commands (e.g. `migrate`,
`optimize:clear`) and scripts that are not artisan commands are not recorded.

### Scheduler health

The plugin does not read the crontab or the process list — it recognises each `schedule:run` **pass** and stores it in the
database (not lost when the cache is cleared). The screen measures the gap between passes ("about every N min") and lists the
hosts running the scheduler. A schedule paused with `php artisan schedule:pause` is reported separately.

### When something goes wrong

- Settings cannot be read (database error, missing table) or the history cannot be written → every task **runs exactly as
  coded**, the error is logged; the plugin never stops the schedule.
- Disabling or removing the plugin → no control layer any more, every task runs as coded.

## Permissions

| Who | Can |
|---|---|
| `administrator` role | Everything |
| `view.all` role | View only |
| **Scheduled tasks — view** permission | View only |
| **Scheduled tasks — configure** permission | View + change schedule, on/off, run now, retry failed jobs |

This is a site-wide setting: store admins of a multi-store site have no access.

## Customising

Do not edit the plugin's `config.php` — that file is replaced on every plugin update. Instead:

1. Create the file `config/scheduled-tasks.php` in the **site root** (next to `config/app.php`).
2. Put only the keys you want to change, for example:

   ```php
   <?php
   return [
       'retention_days' => 30,              // days of run history to keep (default 90)
       'scheduler_stale_minutes' => 5,      // minimum minutes before the scheduler counts as stopped (default 3)
       'record_external' => true,           // record commands run outside the schedule (default on)
       'external_commands' => ['backup:*'], // also watch these package commands outside the schedule
       'run_now_args' => [                  // extra arguments for "Run now", by task identity
           'report:send --daily' => '--force',
       ],
   ];
   ```

3. If the site uses a configuration cache, run `php artisan config:cache` again to load the new values.

Pruning history by hand: `php artisan scheduled-tasks:purge-runs` only **counts** the rows to delete; add `--execute` to really
delete them.

## Uninstall

Uninstalling drops the plugin's three tables (run history, scheduler heartbeats, the schedules / on-off you set); every task
returns to its code schedule. The code of the tasks is not affected.

## License

MIT — free.

## Conditions & Rules (know before you act)

**When changing a schedule**
- **The cron must have exactly 5 fields** (minute hour day month weekday) — a syntax error is reported and the old schedule is
  kept, so a task can never end up "without a schedule".
- **Saving a schedule equal to the code schedule = restore** — no own schedule is stored, so the task follows the code when the
  developer changes it later.
- **Sub-minute tasks cannot get a new schedule** — the smallest cron step is one minute; it cannot express "every 10 seconds".
- **A schedule denser than the code schedule can be saved, with a warning** — a heavy task run too often may overload the hosting.

**When turning on/off or running now**
- **Only tasks still on the code schedule can be controlled** — "No longer in the code" tasks and commands run outside the
  schedule are view only, because there is no (or never was a) schedule to control.
- **"Run now" is locked while the scheduler is not running** — the flag would never be processed; it is locked so you do not
  think it ran.
- **While a task is waiting to run, it cannot be flagged again** — one waiting flag per task; a new flag is accepted once the run
  is done.
- **If the previous run is still going, the new run waits** — two runs of the same task never overlap.

**When handling the queue**
- **Only failed jobs present in the list can be retried**, and **there is no delete** — deleting a job cannot be undone.
- **Pending jobs can only be counted with the `database` driver**; with `sync` the screen says plainly that the site uses no
  queue instead of showing 0.

**Permissions and scope**
- **The "configure" permission** (or the `administrator` role) **is needed for every change** — view-only users do not see the
  buttons, and a forced call is refused.
- **Store admins of a multi-store site have no access** — the schedule belongs to the whole site, not to one store.

## Q&A

**Q1: The screen says "The scheduler has never run" although I installed the plugin?**

→ The plugin does not run the scheduler by itself; the site needs a cron (or supervisor/Docker) calling `schedule:run` every
minute. Follow [Turn the scheduler on](#turn-the-scheduler-on), wait 1–2 minutes, then reload the page.

**Q2: Can I add a new command to run daily right from the screen?**

→ No, on purpose, for security (see [Why commands cannot be added from the screen](#why-commands-cannot-be-added-from-the-screen)).
Ask a developer to declare the task in code; after deployment it appears on the screen for you to control.

**Q3: I changed a schedule — how long until it applies?**

→ From the next scheduler pass, usually within 1 minute. Nothing needs restarting.

**Q4: I clicked "Run now" but the task did not run?**

→ Check the scheduler health line: if the scheduler is not running, the flag is not processed. If the task's previous run is
still going, the new run waits until it finishes. See the result in the task's history.

**Q5: Does turning a task off stop the run in progress?**

→ No. Turning off only prevents the next runs; the run in progress continues until it finishes.

**Q6: The developer changed the schedule in code, but I had set my own schedule — what happens?**

→ Your own schedule is still used, and the screen shows "The code schedule changed: X → Y". To follow the code, click "Restore the
code schedule".

**Q7: Why does the "Outside the schedule" tab not show the command I just ran by hand?**

→ Only GP247/plugin/own-code commands, commands on the schedule and commands matching `external_commands` are recorded. Add a
command name pattern to `external_commands` in `config/scheduled-tasks.php` to watch another package's command.

**Q8: My hosting runs cron every 5 minutes — will the screen keep reporting an error?**

→ No. The plugin measures the real interval and only reports "not running" after max(3 minutes, 2 × interval) — with a 5-minute
cron that is after 10 minutes without a new pass.

**Q9: Does clearing the cache (`php artisan cache:clear`) lose the scheduler status?**

→ No. The scheduler heartbeat is stored in the plugin's database table, not in the cache.

**Q10: Does uninstalling the plugin remove the site's tasks?**

→ No. The tasks live in code, so they keep running on their code schedules. Only the run history and the settings you made on
the screen are removed.

---

<sub>📅 **Last updated:** 2026-10-22 · ✍️ **Author:** GP247</sub>
