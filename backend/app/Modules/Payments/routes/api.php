<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\Admin\BillingPricingController;
use App\Modules\Payments\Http\Controllers\Admin\CollectionReportController;
use App\Modules\Payments\Http\Controllers\Admin\OutstandingCreditsController;
use App\Modules\Payments\Http\Controllers\Admin\PaymentAuditController;
use App\Modules\Payments\Http\Controllers\Admin\PaymentReconciliationController;
use App\Modules\Payments\Http\Controllers\Admin\ReconciliationController;
use App\Modules\Payments\Http\Controllers\BillingController;
use App\Modules\Payments\Http\Controllers\BillingSettingsController;
use App\Modules\Payments\Http\Controllers\CouponController;
use App\Modules\Payments\Http\Controllers\CreditPurchaseController;
use App\Modules\Payments\Http\Controllers\Manage\CreditLimitController;
use App\Modules\Payments\Http\Controllers\Manage\ExamModeController;
use App\Modules\Payments\Http\Controllers\Manage\PlanController;
use App\Modules\Payments\Http\Controllers\Manage\StudentBalanceController;
use App\Modules\Payments\Http\Controllers\OrderController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\SubscriptionController;
use App\Modules\Payments\Http\Controllers\TermsConsentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Payments\Http\Middleware\VerifyWebhookSource;
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

    /*
    | Agreeing to owe (FR-048 · FR-049). `throttle:billing` like the rest of the
    | group and never `throttle:auth`, whose second rule keys on an email this
    | request does not carry.
    |
    | No `{document}` in the path and no student in it either: the document is a
    | body value validated against the enum, and the subject is the signer unless
    | a guardian names a child — which is a 403 in every branch, including one
    | that names nobody.
    */
    Route::get('/billing/consents', [TermsConsentController::class, 'index']);
    Route::post('/billing/consents', [TermsConsentController::class, 'store']);

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

    /*
    | Exam mode (FR-046). The teacher's own calendar, so the workspace is the
    | authenticated one and no id appears in the payload.
    |
    | DELETE carries no uuid on purpose: the question is "turn it off", and
    | closing one row at a time would leave an overlapping second window in force
    | behind a screen showing it as off.
    */
    Route::get('/manage/billing/exam-mode', [ExamModeController::class, 'show']);
    Route::post('/manage/billing/exam-mode', [ExamModeController::class, 'store']);
    Route::delete('/manage/billing/exam-mode', [ExamModeController::class, 'destroy']);

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
    | Subscriptions (011 · US4). The third pricing shape, and the only one of the
    | three that sells TIME — «بالحصّة» and «بعدد من الحصص» are the two credit
    | routes directly above, priced per course from the teacher's approved rate.
    |
    | ⚠️ THE WORKSPACE AND THE PLAN BOTH ARRIVE AS UUIDs IN A QUERY OR A BODY,
    | never as path parameters. `/{plan}` would resolve the model before any
    | guard ran — and `BelongsToWorkspace` protects nothing on a student's path,
    | because a student is a member of no workspace and the scope adds no
    | condition at all. Both are resolved inside their Actions, filtered.
    */
    Route::get('/billing/plans', [SubscriptionController::class, 'plans']);
    Route::get('/billing/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/billing/subscriptions', [SubscriptionController::class, 'store']);

    /*
    | The teacher's half of a plan: the duration and the coverage, never the
    | price (FR-025 · Q4). `SavePlan` refuses `price_minor` outright rather than
    | filtering it out of a form — a teacher who types a number and is told
    | nothing believes they set a price.
    */
    Route::get('/manage/plans', [PlanController::class, 'index']);
    Route::post('/manage/plans', [PlanController::class, 'store']);
    Route::patch('/manage/plans/{plan}', [PlanController::class, 'update']);

});

/*
| What a discount code is worth, before anything is paid (spec 011 · FR-011).
|
| ⚠️ ITS OWN GROUP FOR ITS OWN LIMITER. `throttle:billing` is a shared bucket
| sized for a student reading their balance; this is the one route in the product
| whose entire purpose is to be guessed at, and the two must not draw on one
| counter — putting a keyspace walk and a balance refresh in the same bucket
| means the attacker locks the honest reader out, or the honest reader's ceiling
| is the attacker's budget. `ThrottleRequests` puts no route in the hash, which is
| exactly why inline limits are banned here.
*/
Route::middleware(['auth:sanctum', 'throttle:coupon'])->group(function (): void {
    Route::post('/billing/coupons/preview', [CouponController::class, 'preview']);
});

Route::middleware(['auth:sanctum', 'throttle:billing'])->group(function (): void {
    /*
    | ⛔ THE CATALOGUE, THE PLAN PRICE AND THE CANCELLATION LEFT THIS FILE — deleted
    | 2026-09-05.
    |
    | Six routes (`/admin/billing/packages` × 3, `/admin/plans` × 2, and
    | `/admin/subscriptions/{uuid}/cancel`) were a second door onto
    | `CreditPackageResource`, `PlanResource` and `SubscriptionResource`, and no
    | file under `frontend/src` called one of them. The last of the three became a
    | twin the same day: `CancelSubscription` had been built, tested and
    | unreachable — this route was its only entrance and no list anywhere gave an
    | officer a uuid to send it — so the panel screen was built for it, and the
    | route it replaced went with the others rather than standing as a second
    | writer beside the Action's conditional UPDATE. The panel is the door that is
    | used, and it is the complete twin: `EditPlan::handleRecordUpdate()` runs
    | `SetPlanPrice`, so the negative-price refusal AND the `plan.priced`
    | activity-log entry come with it, and both resources fall through to their
    | own policies for the platform permissions the controllers asked
    | (`billing.packages.manage` · `plans.price`).
    |
    | The rules those routes carried in their comments are still true and still
    | enforced where they belong: a package is retired with `is_active = false`
    | and never deleted (`CreditPackageResource::canDelete()` returns false, so
    | not even a super admin is offered it), and a plan is fetched
    | `withoutWorkspaceScope()` because a platform officer has a
    | `users.last_workspace_id` like everybody else.
    */

    /*
    | What the nightly reconciliation found, read back — never computed here.
    | Three GROUP BYs with no tenant filter would run on every refresh of the
    | screen. The payload carries the LAST RUN TIME, because "no findings" and
    | "the sweep stopped on Tuesday" are otherwise the same empty list.
    */
    Route::get('/admin/billing/reconciliation', [ReconciliationController::class, 'show']);

    /*
    | US2 — what the hourly payment sweep found (FR-016 · FR-017).
    |
    | ⚠️ Under `/admin/payments/`, deliberately beside and not inside
    | `/admin/billing/`: the row above answers "does the credit ledger add up",
    | this one answers "did a payment settle without telling us". Two sweeps, two
    | tables, two questions — one path for both would make "the reconciliation"
    | ambiguous in every conversation that followed.
    |
    | GET only, and guarded by a PLATFORM permission no tenant role holds: the
    | findings name orders across every workspace on the platform.
    */
    Route::get('/admin/payments/reconciliation', [PaymentReconciliationController::class, 'show']);

    /*
    | US4 — every financial decision, and the chain behind one payment.
    |
    | Two routes rather than one with a filter: the list answers "what has been
    | decided lately" and the chain answers "what became of this money". The
    | second is not a narrower version of the first — it reads five tables the
    | list never touches.
    */
    /*
    | US5 — what the platform collected in a period, and the same thing as a file.
    |
    | The export is a SEPARATE PATH rather than a `?format=csv` on the row above,
    | so that a browser can be sent straight at it — but it shares the Request,
    | the filter and the query, which is what FR-034's "the same data and the same
    | restrictions" actually requires. A format flag would have shared them too;
    | what it would not have shared is the ability to hand somebody a link.
    */
    Route::get('/admin/payments/collection', [CollectionReportController::class, 'show']);
    Route::get('/admin/payments/collection/export', [CollectionReportController::class, 'export']);

    Route::get('/admin/payments/audit', [PaymentAuditController::class, 'index']);
    Route::get('/admin/payments/audit/{transaction}', [PaymentAuditController::class, 'show']);

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

/*
| Orders — the money path a credit purchase finishes on.
|
| `throttle:billing` was added in 006's audit (T167), and its absence was not
| cosmetic: uploading a receipt writes a file, and approving one grants an
| enrolment and moves a balance. Both were reachable at request speed. Named
| rather than inline for the reason every other group here is — an inline
| `throttle:N,M` shares one bucket with every other inline limit on the domain.
*/
Route::middleware(['auth:sanctum', 'throttle:billing'])->group(function (): void {
    /*
    | ⚠️ `{orderUuid}` AND NOT `{order}` ON THESE FOUR, AND THE BINDING IS WHY.
    |
    | Implicit binding resolves THROUGH `BelongsToWorkspace`'s global scope, so a
    | row outside the reader's current workspace 404s before any policy runs.
    | That is the right guard for a member — and it is a wall for a platform
    | officer, whose context falls back to `users.last_workspace_id` like
    | everyone else's: an officer who also owns a workspace was answered 404 on
    | every credit order outside it, while `OrderPolicy` was ready to allow it.
    | Measured on 2026-09-03 while implementing 024; invisible until then because
    | every fixture built that officer with no workspace at all.
    |
    | So the four routes the platform can act on resolve in the controller
    | without the scope, and `OrderPolicy` — which already asks the workspace
    | question itself for every non-platform branch — is the one spelling of the
    | decision. The visible change is that a teacher probing another workspace's
    | order now reads 403 instead of 404 here; deliberate, and tested.
    |
    | `/orders/{order}/receipt` (the signed download) keeps implicit binding: it
    | carries no authenticated user, so the context is null and the scope is
    | inert there anyway.
    */
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{orderUuid}', [OrderController::class, 'show']);
    Route::post('/courses/{course}/orders', [OrderController::class, 'store']);
    Route::post('/orders/{orderUuid}/receipt', [OrderController::class, 'uploadReceipt']);
    // Money moves and an enrolment is granted — sensitive by any reading, so
    // the second factor is required here once the account's grace period is up.
    Route::post('/orders/{orderUuid}/approve', [OrderController::class, 'approve'])->middleware('2fa.required');
    Route::post('/orders/{orderUuid}/reject', [OrderController::class, 'reject'])->middleware('2fa.required');
});

/*
| The payer's two routes (spec 007).
|
| Same group as the orders above for the same reason: starting a payment writes
| a transaction row and reaches an external provider.
*/
Route::middleware(['auth:sanctum', 'throttle:billing'])->group(function (): void {
    Route::post('/payments/{order}/charge', [PaymentController::class, 'charge']);
    Route::get('/payments/{transaction}', [PaymentController::class, 'show']);
});

/*
| The provider's notification endpoint — the one unauthenticated write path in
| this module.
|
| ⚠️ NO `auth:sanctum`, BY NECESSITY: a gateway holds no token of ours. The
| signature is the authentication, and it is checked before the body is read.
|
| ⚠️ THE MIDDLEWARE ORDER IS PART OF THE DESIGN. The source guard runs FIRST, so
| that junk from a refused address never reaches the limiter and never consumes
| the provider's bucket — which is what a real resend burst needs to find free.
|
| ⚠️ `throttle:webhook` is named, and keyed by the provider from the path plus
| the address. An inline `throttle:N,M` keys a guest on `domain|ip` with no route
| in the hash, so every inline limit on the platform shares one counter and the
| strictest wins — that is how browsing the marketplace once locked people out of
| logging in.
*/
Route::post('/webhooks/payments/{provider}', WebhookController::class)
    ->middleware([VerifyWebhookSource::class, 'throttle:webhook'])
    ->name('webhooks.payments');
