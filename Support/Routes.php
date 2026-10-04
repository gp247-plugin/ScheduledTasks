<?php

namespace App\GP247\Plugins\ScheduledTasks\Support;

use App\GP247\Plugins\ScheduledTasks\Livewire\TaskDetail;
use App\GP247\Plugins\ScheduledTasks\Livewire\TaskIndex;
use Illuminate\Support\Facades\Route;

/**
 * The two admin screens. Every write is a Livewire action re-authorized on the
 * server, so no POST route exists.
 *
 * @aidlc-unit plugin-scheduled-tasks
 * @aidlc-story US-plugin-scheduled-tasks-access-control
 */
final class Routes
{
    public const URI = 'scheduled-tasks';

    /**
     * Register the admin routes.
     *
     * @return void
     *
     * @aidlc-unit plugin-scheduled-tasks
     * @aidlc-story US-plugin-scheduled-tasks-access-control
     */
    public static function register(): void
    {
        Route::group(
            [
                'prefix' => GP247_ADMIN_PREFIX . '/' . self::URI,
                'middleware' => GP247_ADMIN_MIDDLEWARE,
            ],
            static function (): void {
                Route::get('/', TaskIndex::class)->name('admin_scheduled_tasks.index');
                // WHY "{id}": core's Layer-2 matcher understands the "{id}" suffix of a permission URI.
                Route::get('/{id}', TaskDetail::class)->whereNumber('id')->name('admin_scheduled_tasks.detail');
            }
        );
    }
}
