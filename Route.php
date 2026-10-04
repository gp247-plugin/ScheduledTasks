<?php
/**
 * Admin routes of the ScheduledTasks plugin (only while the plugin is active).
 */
$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    \App\GP247\Plugins\ScheduledTasks\Support\Routes::register();
}
