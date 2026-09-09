<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ListCreditPackages;
use App\Modules\Payments\Actions\ListPurchasableCourses;
use App\Modules\Payments\Actions\ListPurchaseBeneficiaries;
use App\Modules\Payments\Actions\PurchaseCredits;
use App\Modules\Payments\Http\Requests\PurchaseCreditsRequest;
use App\Modules\Payments\Http\Resources\CreditPackageOfferResource;
use App\Modules\Payments\Http\Resources\PurchasableCourseResource;
use App\Modules\Payments\Http\Resources\PurchaseBeneficiaryResource;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\CourseParticipation;
use App\Modules\Payments\Support\PurchaseBeneficiary;
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
    public function index(
        Request $request,
        ListCreditPackages $action,
        PurchaseBeneficiary $beneficiary,
    ): AnonymousResourceCollection {
        ['student' => $student, 'grantedBy' => $grantedBy] = $beneficiary->resolve(
            $this->currentUser($request),
            $this->studentUuidIn($request),
        );

        $offers = $action->handle($student, $this->courseFor($request->query('course')), $grantedBy);

        return CreditPackageOfferResource::collection($offers);
    }

    public function store(
        PurchaseCreditsRequest $request,
        PurchaseCredits $action,
        PurchaseBeneficiary $beneficiary,
    ): JsonResponse {
        $package = CreditPackage::query()
            ->where('uuid', $request->validated('package'))
            ->firstOrFail();

        /*
        | ⚠️ THE SAME RESOLVER THE SUBSCRIPTION DOOR USES, NOT A SECOND READING OF
        | THE SAME FIELD. It proves the guardianship and the «payments» permission,
        | and answers all three ways of being wrong with one sentence — which is
        | what stops the field being an identity probe.
        */
        ['student' => $student, 'grantedBy' => $grantedBy] = $beneficiary->resolve(
            $this->currentUser($request),
            $request->validated('student_uuid') !== null ? (string) $request->validated('student_uuid') : null,
        );

        $purchase = $action->handle(
            $student,
            $this->courseFor($request->validated('course')),
            $package,
            $request->validated('coupon_code'),
            $grantedBy,
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
    /**
     * Who this caller may pay for (FR-012 · FR-018).
     *
     * ⚠️ NO `student_uuid` HERE, AND NOTHING TO RESOLVE. It answers about the
     * CALLER, which is what makes it the one door the picker can be built from —
     * a list that took a subject would be a list that could be asked about
     * somebody else.
     */
    public function beneficiaries(Request $request, ListPurchaseBeneficiaries $action): AnonymousResourceCollection
    {
        return PurchaseBeneficiaryResource::collection($action->handle($this->currentUser($request)));
    }

    /**
     * The courses this caller may buy credits on, for themselves or for a child.
     *
     * ⚠️ THE SAME RESOLVER AS THE OTHER TWO DOORS, so a guardian who names nobody
     * gets «اختر الطالب الذي تدفع له.» here as well — the picker's first screen
     * asks WHO before it asks WHICH, and a list of «my own courses» shown to a
     * guardian would be empty for a reason they cannot act on.
     *
     * ⚠️ AND THE COLLECTION IS RETURNED DIRECTLY, never through `response()->json()`.
     * That call never reaches `toResponse()`, so `links` and `meta` are dropped in
     * silence and every reader is stuck on page one — and on `/enrollments` it
     * dropped the whole envelope and showed every student on the platform zero.
     */
    public function purchasableCourses(
        Request $request,
        ListPurchasableCourses $action,
        PurchaseBeneficiary $beneficiary,
    ): AnonymousResourceCollection {
        ['student' => $student, 'grantedBy' => $grantedBy] = $beneficiary->resolve(
            $this->currentUser($request),
            $this->studentUuidIn($request),
        );

        return PurchasableCourseResource::collection($action->handle($student, $grantedBy));
    }

    /**
     * The beneficiary named on a GET, shaped before it reaches the resolver.
     *
     * ⚠️ A QUERY STRING IS NOT A VALIDATED BODY. `?student_uuid[]=x` arrives as an
     * ARRAY, and handing that to a `where('uuid', …)` is a type error at best;
     * anything that is not a non-empty string therefore reads as «nobody named»,
     * which is the pre-031 meaning and the safe one.
     *
     * The SHAPE is all that is checked here, deliberately: a uuid-format rule
     * would answer 422 «malformed» for a wrong shape and the uniform sentence for
     * a well-formed stranger, which is the distinction `PurchaseCreditsRequest`
     * refuses `exists:` to avoid. A string that names nobody simply names nobody.
     */
    private function studentUuidIn(Request $request): ?string
    {
        $raw = $request->query('student_uuid');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    private function courseFor(mixed $uuid): Course
    {
        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages(['course' => 'حدّد الكورس أولاً.']);
        }

        $course = Course::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();

        if ($course === null) {
            throw new AuthorizationException(CourseParticipation::NOT_A_PARTY);
        }

        return $course;
    }
}
