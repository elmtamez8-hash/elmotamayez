<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/courses/{course}/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/receipt', [OrderController::class, 'uploadReceipt']);
    Route::post('/orders/{order}/approve', [OrderController::class, 'approve']);
    Route::post('/orders/{order}/reject', [OrderController::class, 'reject']);
});
