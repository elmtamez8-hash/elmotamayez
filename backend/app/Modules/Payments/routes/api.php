<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
| Receipts are financial documents — bank transfer details tied to a named
| person — so they live on the private disk and are never served as a static
| file. This route is the only way to read one, and it is reachable by signature
| alone because the browser opens it as a top-level navigation with no bearer
| token. OrderResource mints the signature, and only for a viewer the `view`
| policy already allowed.
*/
Route::get('/orders/{order}/receipt', [OrderController::class, 'downloadReceipt'])
    ->middleware('signed')
    ->name('orders.receipt');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/courses/{course}/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/receipt', [OrderController::class, 'uploadReceipt']);
    // Money moves and an enrolment is granted — sensitive by any reading, so
    // the second factor is required here once the account's grace period is up.
    Route::post('/orders/{order}/approve', [OrderController::class, 'approve'])->middleware('2fa.required');
    Route::post('/orders/{order}/reject', [OrderController::class, 'reject'])->middleware('2fa.required');
});
