<?php

declare(strict_types=1);

use App\Modules\Gamification\Http\Controllers\LeaderboardController;
use App\Modules\Gamification\Http\Controllers\ProgressController;
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
});
