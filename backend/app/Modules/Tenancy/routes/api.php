<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch']);
    Route::patch('/workspaces/{workspace}', [WorkspaceController::class, 'update']);
    Route::get('/workspaces/{workspace}/members', [WorkspaceController::class, 'members']);
    Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceController::class, 'removeMember']);
    Route::post('/workspaces/{workspace}/invitations', [WorkspaceController::class, 'invite']);
    Route::post('/workspaces/invitations/{token}/accept', [WorkspaceController::class, 'acceptInvitation']);
});
