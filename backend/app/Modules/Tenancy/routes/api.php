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

// Public: the invitee reads the invitation before they have an account.
Route::get('/workspaces/invitations/{token}', [WorkspaceController::class, 'showInvitation']);

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
