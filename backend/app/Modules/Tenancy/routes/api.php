<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

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
