<?php
/**
 * Provides everything needed for the ScheduledTasks plugin.
 *
 * Everything that touches the scheduler is registered only while the plugin is
 * active: a disabled or removed plugin leaves every schedule exactly as coded.
 */

$config = file_get_contents(__DIR__.'/gp247.json');
$config = json_decode($config, true);
$extensionPath = $config['configGroup'].'/'.$config['configKey'];

$this->loadTranslationsFrom(__DIR__.'/Lang', $extensionPath);

if (gp247_extension_check_active($config['configGroup'], $config['configKey'])) {
    $this->loadViewsFrom(__DIR__.'/Views', $extensionPath);
    $this->mergeConfigFrom(__DIR__.'/config.php', $extensionPath);

    \App\GP247\Plugins\ScheduledTasks\Scheduling\Registrar::register($this->app);

    if (class_exists(\Livewire\Livewire::class)) {
        \Livewire\Livewire::addNamespace('ScheduledTasks', classNamespace: 'App\\GP247\\Plugins\\ScheduledTasks\\Livewire');
    }
}
