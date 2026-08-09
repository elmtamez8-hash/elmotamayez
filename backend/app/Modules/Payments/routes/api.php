<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Admin\BillingPricingController;
use App\Modules\Payments\Http\Controllers\Admin\CreditPackageAdminController;
use App\Modules\Payments\Http\Controllers\Admin\OutstandingCreditsController;
use App\Modules\Payments\Http\Controllers\BillingController;
use App\Modules\Payments\Http\Controllers\BillingSettingsController;
use App\Modules\Payments\Http\Controllers\CreditPurchaseController;
use App\Modules\Payments\Http\Controllers\Manage\CreditLimitController;
use App\Modules\Payments\Http\Controllers\Manage\StudentBalanceController;
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

/*
| Billing reads (spec 006).
|
| `throttle:billing`, never `throttle:auth`: that limiter's second rule keys on
| `'email:'.$request->input('email')`, and a billing request carries no email —
| so the key collapses to the constant `'email:'` and every visitor on the
| platform shares one bucket. One person refreshing their balance would lock
| everyone else out of buying credits.
*/
Route::middleware(['auth:sanctum', 'throttle:billing'])->group(function (): void {
    Route::get('/billing/balance', [BillingController::class, 'balance']);
    Route::get('/billing/transactions', [BillingController::class, 'transactions']);

    // The guardian's read. A separate route rather than a `student` parameter on
    // the one above, because it has to prove the relation AND the Payments
    // consent — and folding the two together would make the student's own route
    // carry a check it should never have to answer.
    Route::get('/billing/children/balance', [BillingController::class, 'childBalance']);

    // The teacher's panel: credits and withheld state for their own students,
    // with no money in the payload (StudentBalanceAllowlist).
    Route::get('/manage/billing/students', [StudentBalanceController::class, 'index']);

    /*
    | The exception FR-038 allows: a ceiling moved by hand, with a recorded
    | reason. A PLATFORM permission, not the teacher's — and `{student}` is a
    | plain string, never bound to a User: route-model binding resolves by uuid
    | before any guard in the controller runs, which turns a bare uuid into a
    | fact about a real person.
    */
    Route::patch('/manage/billing/students/{student}/limit', [CreditLimitController::class, 'update']);

    // FR-011 — the mode is switched from settings, never by shipping code. Both
    // verbs carry the workspace implicitly: it is the one the request is already
    // authenticated into, so there is no id anyone could substitute.
    Route::get('/manage/billing/settings', [BillingSettingsController::class, 'show']);
    Route::patch('/manage/billing/settings', [BillingSettingsController::class, 'update']);

    // Buying credits. The course arrives as a QUERY/BODY value, never as a path
    // parameter: `/{course}` would resolve the model by uuid before any guard
    // ran, and this is the surface where a total is invertible back to a
    // teacher's approved settlement rate.
    Route::get('/billing/packages', [CreditPurchaseController::class, 'index']);
    Route::post('/billing/purchases', [CreditPurchaseController::class, 'store']);

    /*
    | The platform's catalogue and the platform's half of the price (FR-016 ·
    | FR-021أ). Both are guarded by PLATFORM permissions that no tenant role
    | holds — a package a teacher could define is a sale price a teacher sets,
    | which FR-021ب forbids — so the workspace owner fails every write here.
    |
    | No DELETE on packages: retirement is `is_active = false` (FR-019). Credits
    | already bought keep pointing at the row, and deleting it would orphan
    | purchases that have been paid for.
    */
    Route::get('/admin/billing/packages', [CreditPackageAdminController::class, 'index']);
    Route::post('/admin/billing/packages', [CreditPackageAdminController::class, 'store']);
    Route::patch('/admin/billing/packages/{uuid}', [CreditPackageAdminController::class, 'update']);

    Route::get('/admin/billing/pricing', [BillingPricingController::class, 'show']);
    Route::put('/admin/billing/pricing', [BillingPricingController::class, 'update']);

    /*
    | Read by the RATE-APPROVAL screen, and deliberately not part of its payload:
    | a `credits_sold` field on a Settlement response would be computed from
    | `credit_purchases`, which is the coupling ContextIsolationTest exists to
    | refuse. Two contexts, two requests (Q-7 · T097).
    */
    Route::get('/admin/billing/outstanding', [OutstandingCreditsController::class, 'show']);
});

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
