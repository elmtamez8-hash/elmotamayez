<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Http\Requests\PurchaseCreditsRequest;
use App\Modules\Payments\Http\Resources\CreditPackageOfferResource;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Buying credits: what is on offer, and starting a purchase.
 *
 * ⚠️ NEITHER ROUTE BINDS A MODEL IMPLICITLY. `{course}` in the path would resolve
 * by uuid before any guard ran, and the pricing surface is exactly the one where
 * that matters: the total is invertible back to a teacher's approved settlement
 * rate by anyone who can read two of them (see CourseParticipation). Both
 * lookups therefore go through {@see self::courseFor()}, which finds the course
 * and hands it to an Action that proves participation before pricing anything.
 */
class CreditPurchaseController extends Controller
{
    public function index(Request $request, ListCreditPackages $action): AnonymousResourceCollection
    {
        $offers = $action->handle(
            $this->currentUser($request),
            $this->courseFor($request->query('course')),
        );

        return CreditPackageOfferResource::collection($offers);
    }

    public function store(PurchaseCreditsRequest $request, PurchaseCredits $action): JsonResponse
    {
        $package = CreditPackage::query()
            ->where('uuid', $request->validated('package'))
            ->firstOrFail();

        $purchase = $action->handle(
            $this->currentUser($request),
            $this->courseFor($request->validated('course')),
            $package,
            $request->validated('coupon_code'),
        );

        /*
        | The ORDER uuid, not the purchase's, is what the client needs next: the
        | receipt is uploaded against the order, on the manual path that already
        | exists. Returning the purchase uuid would mean a second lookup on a row
        | the student has no route to.
        */
        /*
        | ⚠️ `withoutWorkspaceScope()`, AND THE FAILURE IT PREVENTS LANDS AFTER THE
        | COMMIT. `CreditPurchase::order()` is a plain BelongsTo, so the relation
        | query carries `Order`'s own workspace scope — and a buyer whose
        | `users.last_workspace_id` is not the course's workspace (a platform
        | officer, or a guardian who owns one) matched zero rows and got a 404 on a
        | transaction that had already succeeded. What is left behind is a pending
        | order whose uuid the client never learns — and the receipt is uploaded
        | against the order, which is the whole reason this line returns it — plus
        | its price snapshot and a redeemed single-use coupon. Nothing mints
        | credits here (FR-018), so no balance is wrong; the order is simply
        | unpayable for ever. And once the ceiling counts `under_review` correctly,
        | that stranded row consumes the cap permanently: two failed attempts at a
        | ceiling of six lock the student out of that course until a human looks.
        */
        return response()->json([
            'order' => Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($purchase->order_id)
                ->firstOrFail()
                ->uuid,
            'credits' => $purchase->credits,
            'total_minor' => $purchase->total_minor,
            'currency' => $purchase->currency,
        ], 201);
    }

    /**
     * The course this request names.
     *
     * withoutWorkspaceScope, deliberately: a student studying with three
     * teachers has one current workspace, and buying credits for the other two
     * would 404 against the scope. The guard is participation, checked in the
     * Action — which is a stronger question than the scope was asking.
     *
     * ⚠️ AND A MISSING COURSE ANSWERS LIKE A FORBIDDEN ONE. `firstOrFail()` was a
     * 404 while a real course the caller is not party to is a 403 — so any signed-in
     * caller could tell a live course uuid from a dead one, which is the oracle
     * `PurchaseCreditsRequest` refuses `exists:` on the student field to avoid,
     * one door along. Course uuids are v4 so nothing is enumerable; what leaks is
     * CONFIRMATION of a uuid obtained elsewhere.
     *
     * The price is written down rather than discovered: a genuinely mistyped uuid
     * now reads as a permission problem. That is the trade this tree already makes
     * in `PurchaseBeneficiary` and `LinkGuardian`, and one answer for both branches
     * beats two answers that differ by whether the row happens to exist.
     */
    private function courseFor(mixed $uuid): Course
    {
        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages(['course' => 'حدّد الكورس أولاً.']);
        }

        $course = Course::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

        if ($course === null) {
            throw new AuthorizationException('لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');
        }

        return $course;
    }
}
