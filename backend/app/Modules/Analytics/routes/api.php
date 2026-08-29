<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\ReportSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform analytics (spec 011 · US6)
|--------------------------------------------------------------------------
|
| Every route here is guarded by `analytics.cross_teacher.view`, a PLATFORM
| permission held by no tenant role — these numbers add every workspace together,
| so the workspace-level `analytics.view` would be the wrong door by exactly the
| distance between one teacher and the whole platform.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/reports/platform', [ReportSubscriptionController::class, 'report']);
    Route::get('/reports/subscriptions', [ReportSubscriptionController::class, 'show']);
    Route::put('/reports/subscriptions', [ReportSubscriptionController::class, 'save']);
});
