<?php
#App\GP247\Plugins\ScheduledTasks\AppConfig.php
namespace App\GP247\Plugins\ScheduledTasks;

use App\GP247\Plugins\ScheduledTasks\Models\ExtensionModel;
use App\GP247\Plugins\ScheduledTasks\Support\Routes;
use GP247\Core\ExtensionConfigDefault;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminHome;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;

/**
 * Install / update / uninstall of the ScheduledTasks plugin.
 *
 * install() and update() share converge(): it brings any site (fresh, partially
 * installed, older version) to the shape this version needs, and is a no-op on a
 * healthy one.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-access-control
 */
class AppConfig extends ExtensionConfigDefault
{
    private const MENU_PARENT_KEY = 'ADMIN_SYSTEM';

    /**
     * Read the plugin manifest.
     */
    public function __construct()
    {
        $config = json_decode((string) file_get_contents(__DIR__.'/gp247.json'), true);
        $this->configGroup = $config['configGroup'];
        $this->configKey = $config['configKey'];
        $this->configCode = $config['configCode'] ?? $this->configKey;
        $this->requireCore = $config['requireCore'] ?? [];
        $this->requireComposerPackages = $config['requireComposerPackages'] ?? [];
        $this->requireGp247Extensions = $config['requireGp247Extensions'] ?? [];
        $this->appPath = $this->configGroup . '/' . $this->configKey;
        $this->title = trans($this->appPath.'::lang.title');
        $this->image = $this->appPath.'/'.$config['image'];
        $this->version = $config['version'];
        $this->auth = $config['auth'];
        $this->link = $config['link'];
    }

    /**
     * The two permissions the plugin adds (view / configure).
     *
     * WHY two URI shapes: the route middleware matches GET routes exactly
     * ("{id}" placeholder included), while ANY::…/* covers the list, the detail
     * page and the Livewire write actions authorized as POST on those screens.
     *
     * @return list<array{slug: string, name: string, http_uri: string}>
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public static function permissionDefinitions(): array
    {
        $base = (defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin') . '/' . Routes::URI;

        return [
            [
                'slug' => 'scheduled-tasks.view',
                'name' => 'Scheduled tasks — view',
                'http_uri' => 'GET::' . $base . ',GET::' . $base . '/{id}',
            ],
            [
                'slug' => 'scheduled-tasks.manage',
                'name' => 'Scheduled tasks — configure',
                'http_uri' => 'ANY::' . $base . '/*',
            ],
        ];
    }

    /**
     * Register the plugin, then converge tables, menu and permissions.
     *
     * @return array{error: int, msg: string}
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function install()
    {
        if (AdminConfig::where('key', $this->configKey)->where('group', $this->configGroup)->exists()) {
            return ['error' => 1, 'msg' => gp247_language_render('admin.extension.plugin_exist')];
        }

        try {
            AdminConfig::insert([[
                'group' => $this->configGroup,
                'code' => $this->configCode,
                'key' => $this->configKey,
                'sort' => 0,
                'store_id' => GP247_STORE_ID_GLOBAL,
                'value' => self::ON,
                'detail' => $this->appPath.'::lang.title',
            ]]);
            $this->converge();

            return ['error' => 0, 'msg' => gp247_language_render('admin.extension.install_success')];
        } catch (\Throwable $e) {
            return ['error' => 1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Run after the 1-click updater replaced the plugin files.
     *
     * @param string|null $fromVersion Version installed before the update.
     * @return array{error: int, msg: string}
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function update(?string $fromVersion = null)
    {
        try {
            $this->converge();
        } catch (\Throwable $e) {
            return ['error' => 1, 'msg' => $e->getMessage()];
        }

        return ['error' => 0, 'msg' => ''];
    }

    /**
     * Remove what install() created, including the plugin tables (history and the
     * owner's schedule settings — every task then runs as coded).
     *
     * @return array{error: int, msg: string}
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public function uninstall()
    {
        try {
            (new AdminConfig)
                ->where('key', $this->configKey)
                ->orWhere('code', $this->configKey.'_config')
                ->delete();
            AdminHome::where('extension', $this->appPath)->delete();
            AdminMenu::where('key', $this->configKey)->delete();
            AdminPermission::whereIn('slug', array_column(self::permissionDefinitions(), 'slug'))->delete();
            app(ExtensionModel::class)->uninstallExtension();

            return ['error' => 0, 'msg' => gp247_language_render('admin.extension.uninstall_success')];
        } catch (\Throwable $e) {
            return ['error' => 1, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Enable the plugin.
     *
     * @return array{error: int, msg: string}
     */
    public function enable()
    {
        (new AdminConfig)->where('group', $this->configGroup)->where('key', $this->configKey)->update(['value' => self::ON]);

        return ['error' => 0, 'msg' => gp247_language_render('admin.extension.enable_success')];
    }

    /**
     * Disable the plugin (every schedule then runs exactly as coded).
     *
     * @return array{error: int, msg: string}
     */
    public function disable()
    {
        (new AdminConfig)->where('group', $this->configGroup)->where('key', $this->configKey)->update(['value' => self::OFF]);

        return ['error' => 0, 'msg' => ''];
    }

    /**
     * Site-wide plugin: nothing to set up per store.
     *
     * @param mixed $storeId
     * @return void
     */
    public function setupStore($storeId = null)
    {
    }

    /**
     * Site-wide plugin: nothing to remove per store.
     *
     * @param mixed $storeId
     * @return void
     */
    public function removeStore($storeId = null)
    {
    }

    /**
     * No action on the plugin button.
     *
     * @return void
     */
    public function clickApp()
    {
    }

    /**
     * Plugin information for the extension screens.
     *
     * @return array<string, mixed>
     */
    public function getInfo()
    {
        return [
            'title' => $this->title,
            'key' => $this->configKey,
            'code' => $this->configCode,
            'image' => $this->image,
            'permission' => self::ALLOW,
            'version' => $this->version,
            'auth' => $this->auth,
            'link' => $this->link,
            'value' => 0,
            'appPath' => $this->appPath,
        ];
    }

    /**
     * Bring tables, menu entry and permissions to this version's shape, without data loss.
     *
     * @return void
     */
    protected function converge(): void
    {
        app(ExtensionModel::class)->installExtension();
        $this->installMenu();
        $this->installPermissions();
    }

    /**
     * Add the menu entry under System once.
     *
     * @return void
     */
    protected function installMenu(): void
    {
        if (AdminMenu::where('key', $this->configKey)->exists()) {
            return;
        }

        $parent = AdminMenu::where('key', self::MENU_PARENT_KEY)->first();
        if (!$parent) {
            return;
        }

        AdminMenu::insert([
            'sort' => 90,
            'parent_id' => $parent->id,
            'title' => $this->appPath.'::lang.title',
            'icon' => 'fas fa-clock',
            'uri' => 'route_admin::admin_scheduled_tasks.index',
            'key' => $this->configKey,
        ]);
    }

    /**
     * Create or repair the two permissions.
     *
     * @return void
     */
    protected function installPermissions(): void
    {
        foreach (self::permissionDefinitions() as $definition) {
            $permission = AdminPermission::firstOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'http_uri' => $definition['http_uri']]
            );
            if ($permission->http_uri !== $definition['http_uri']) {
                $permission->update(['http_uri' => $definition['http_uri']]);
            }
        }
    }
}
