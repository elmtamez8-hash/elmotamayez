<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\PlatformIdentityController;
use App\Modules\Tenancy\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Public: the product's own name (spec 022 follow-up).
|
| ⚠️ READ BEFORE ANYBODY HAS AN ACCOUNT — it is in the `<title>` of every public
| page, on the login screen and in the web manifest — so `auth:sanctum` here
| would mean the sign-in page could not spell the product it signs you in to.
|
| `throttle:public` is the named limiter every public read uses; an inline
| `throttle:n,m` shares one counter with every other inline limit in the app, and
| browsing the marketplace used to lock a visitor out of logging in.
*/
Route::get('/platform', PlatformIdentityController::class)->middleware('throttle:public');

/*
| Public: the invitee reads the invitation before they have an account.
|
| ⛔ IT CARRIED NO LIMITER AND THE `api` GROUP HAD NO DEFAULT — `bootstrap/app.php`
| called `statefulApi()` and `appendToGroup()` and never `throttleApi()`. So an
| unauthenticated route that queries the database and answers with the INVITEE'S
| EMAIL was unlimited, while the line above it in this same file carries
| `throttle:public`. The token itself is `Str::random(64)` and is not the exposure;
| the cost is availability and enumeration.
*/
Route::get('/workspaces/invitations/{token}', [WorkspaceController::class, 'showInvitation'])
    ->middleware('throttle:public');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch']);
    // Workspace settings and membership: who gets in, and what the tenant is.
    Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->middleware('2fa.required');
    Route::get('/workspaces/{workspace}/members', [WorkspaceController::class, 'members']);
    Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceController::class, 'removeMember'])
        ->middleware('2fa.required');
    Route::post('/workspaces/{workspace}/invitations', [WorkspaceController::class, 'invite'])
        ->middleware('2fa.required');
    Route::post('/workspaces/invitations/{token}/accept', [WorkspaceController::class, 'acceptInvitation']);
});
