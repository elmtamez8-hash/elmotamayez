<?php

declare(strict_types=1);

use App\Modules\Marketplace\Http\Controllers\PublicMarketplaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketplace (no authentication)
|--------------------------------------------------------------------------
|
| These routes serve anonymous visitors, which means WorkspaceScope filters
| nothing on them — it returns early when there is no authenticated user. The
| replacement guard is the publiclyListed() scope inside each Action.
|
| Throttling is by IP because there is no actor to key on. Without it the whole
| teacher directory can be scraped in a few minutes.
|
*/

Route::middleware('throttle:60,1')->prefix('marketplace')->name('marketplace.')->group(function (): void {
    Route::get('/home', [PublicMarketplaceController::class, 'home'])->name('home');
    Route::get('/stats', [PublicMarketplaceController::class, 'stats'])->name('stats');
    Route::get('/subjects', [PublicMarketplaceController::class, 'subjects'])->name('subjects');
    Route::get('/grade-levels', [PublicMarketplaceController::class, 'gradeLevels'])->name('grade-levels');
    Route::get('/teachers', [PublicMarketplaceController::class, 'teachers'])->name('teachers.index');

    // Bound as a plain string, not a route model: implicit binding resolves by uuid
    // without the publiclyListed() guard, which would make unpublished profiles
    // reachable by url.
    Route::get('/teachers/{uuid}', [PublicMarketplaceController::class, 'teacher'])->name('teachers.show');
});
