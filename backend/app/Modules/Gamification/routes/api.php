<?php

declare(strict_types=1);

use App\Modules\Gamification\Http\Controllers\LeaderboardController;
use App\Modules\Gamification\Http\Controllers\ProgressController;
use App\Modules\Gamification\Http\Controllers\RewardController;
use App\Modules\Gamification\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

/*
 * Routes for the Gamification module.
 *
 * App\Shared\Modules\Module adds the `/api/v1` prefix and the `api` middleware
 * group automatically — do not repeat either here.
 *
 * Every limiter is NAMED. An inline `throttle:20,1` is banned: ThrottleRequests
 * keys guests on domain|ip with no route in the hash, so every inline limit
 * shares one counter and the strictest one wins for the whole site.
 */

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     | The student's own profile. No limiter: it is five indexed queries behind
     | authentication and scoped to the caller's own rows, and throttling the
     | screen a student refreshes to watch their streak is throttling the feature.
     */
    Route::get('/gamification/me', [ProgressController::class, 'me']);

    /*
     | A teacher reading one of their own students.
     |
     | ⚠️ `{user}` IS A STRING PARAMETER, NEVER AN IMPLICIT BINDING. Bound, Laravel
     | would load any account on the platform before a single check ran — and the
     | payload would come back carrying their name. The Action resolves it AFTER
     | the permission and the active-enrollment check.
     */
    Route::get('/gamification/students/{user}', [ProgressController::class, 'show']);

    /*
     | The leaderboard.
     |
     | ⚠️ THE ONE READ IN THIS PHASE THAT CARRIES A LIMITER, and NFR-014 did not
     | ask for it — it names writes only. This is the endpoint worth enumerating:
     | the scope key names a lesson, a course, a teacher, a subject or a grade, so
     | an unthrottled board is a walk of the whole space collecting who is active
     | where. Generous, because a student refreshing their rank is the feature.
     */
    Route::get('/gamification/leaderboard', [LeaderboardController::class, 'index'])
        ->middleware('throttle:gamification-board');

    /*
     | The shop, student side.
     |
     | ⚠️ `{reward}` IS A STRING, NOT A BOUND MODEL, and that is the single most
     | important line in this file. `WorkspaceScope` is INERT for a student — they
     | are a member of no workspace, so the scope adds no condition — which means
     | an implicit binding resolves ANY teacher's reward and BelongsToWorkspace
     | stops nothing. The Action resolves it after checking the enrolment.
     */
    Route::get('/gamification/shop', [ShopController::class, 'index']);
    Route::get('/gamification/redemptions', [ShopController::class, 'redemptions']);
    Route::post('/gamification/rewards/{reward}/redeem', [ShopController::class, 'redeem'])
        ->middleware('throttle:gamification-write');

    /*
     | The shop, teacher side. Route-model binding IS safe here: the reader is a
     | workspace member, so the scope resolves and applies.
     */
    Route::get('/manage/gamification/rewards', [RewardController::class, 'index']);
    Route::post('/manage/gamification/rewards', [RewardController::class, 'store'])
        ->middleware('throttle:gamification-write');
    Route::put('/manage/gamification/rewards/{reward}', [RewardController::class, 'update'])
        ->middleware('throttle:gamification-write');

    Route::get('/manage/gamification/redemptions', [RewardController::class, 'redemptions']);
    Route::post('/manage/gamification/redemptions/{redemption}/fulfill', [RewardController::class, 'fulfill'])
        ->middleware('throttle:gamification-write');
    Route::post('/manage/gamification/redemptions/{redemption}/reject', [RewardController::class, 'reject'])
        ->middleware('throttle:gamification-write');
});
