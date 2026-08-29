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
        return response()->json([
            'order' => $purchase->order()->firstOrFail()->uuid,
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
     */
    private function courseFor(mixed $uuid): Course
    {
        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages(['course' => 'حدّد الكورس أولاً.']);
        }

        return Course::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    }
}
