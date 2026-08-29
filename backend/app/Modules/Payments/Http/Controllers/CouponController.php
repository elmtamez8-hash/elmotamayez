<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\PreviewDiscount;
use App\Modules\Payments\Enums\CouponScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a code would be worth, before anything is paid (spec 011 · FR-011).
 *
 * ⚠️ ONE ROUTE, `throttle:coupon`, AND NOTHING ELSE. Authoring a coupon is a
 * PLATFORM act (FR-010) and lives in Filament, where the permission
 * `billing.coupons.manage` — held by no tenant role — already guards it. An HTTP
 * CRUD beside it would be a second door onto the same table with its own
 * authorisation to get wrong.
 */
class CouponController extends Controller
{
    public function preview(Request $request, PreviewDiscount $action): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:store_item,course,credit_package'],
            'uuid' => ['required', 'string', 'uuid'],
            // Nullable so the screen can ask «what do I get with no code» — which
            // is how the family discount reaches a buyer who never types
            // anything, and FR-011 asks for it to be shown before payment just as
            // loudly as a coupon.
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $discount = $action->handle(
            $this->currentUser($request),
            CouponScope::from($validated['kind']),
            $validated['uuid'],
            $validated['code'] ?? null,
        );

        return response()->json($discount->toArray());
    }
}
