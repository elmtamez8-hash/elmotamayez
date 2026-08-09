<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's half of the price (FR-021أ · FR-021ب).
 *
 * The operating fee and the gateway's cut are the platform's own numbers, and
 * FR-021ب forbids a teacher setting or seeing them — so this is a platform
 * permission, and the workspace owner fails it.
 *
 * ⚠️ CHANGING THESE REPRICES NOTHING ALREADY BOUGHT. Every purchase carries its
 * own four-part snapshot (FR-021ح), written once and never recomputed, so a fee
 * raised today applies to the next sale and to no earlier one. That is the same
 * rule FR-021ز states for the teacher's rate, and it holds here for free
 * precisely because the snapshot is stored rather than derived.
 *
 * Rows in `platform_settings`, not constants in `config/billing.php`: that file
 * is the fallback for a database with nothing seeded (FR-037).
 */
class BillingPricingController extends Controller
{
    public function show(Request $request, BillingSettings $settings): JsonResponse
    {
        $this->authorizePricing($request);

        return response()->json($this->payload($settings));
    }

    public function update(Request $request, BillingSettings $settings): JsonResponse
    {
        $this->authorizePricing($request);

        $data = $request->validate([
            'operating_fee_minor.individual' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'operating_fee_minor.group' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            // Strictly under 10000 bp: a gateway keeping the whole payment makes
            // the gross-up equation unsolvable, and CostPlusPricing throws rather
            // than charge a number produced by a division that went negative.
            'gateway_fee_bps' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'gateway_fixed_fee_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            // The two escrow guards (Q-11), tuned from the same screen for the
            // same reason the fees are: they are platform numbers read on the
            // purchase path, and a limit that needs a deploy is a limit nobody
            // ever moves.
            'stop_selling_after_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'max_unredeemed_credits' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        foreach ([
            'billing.operating_fee_minor.individual' => data_get($data, 'operating_fee_minor.individual'),
            'billing.operating_fee_minor.group' => data_get($data, 'operating_fee_minor.group'),
            'billing.gateway_fee_bps' => $data['gateway_fee_bps'] ?? null,
            'billing.gateway_fixed_fee_minor' => $data['gateway_fixed_fee_minor'] ?? null,
            'billing.stop_selling_after_days' => $data['stop_selling_after_days'] ?? null,
            'billing.max_unredeemed_credits' => $data['max_unredeemed_credits'] ?? null,
        ] as $key => $value) {
            if ($value !== null) {
                PlatformSettings::set($key, $value, $this->currentUser($request)->getKey());
            }
        }

        return response()->json($this->payload($settings));
    }

    private function authorizePricing(Request $request): void
    {
        // A permission, not a policy: there is no model to attach one to — these
        // are rows in a key/value table that belongs to the platform.
        abort_unless(
            $this->currentUser($request)->can(Permissions::BILLING_PRICING_MANAGE),
            403,
            'تعديل تسعير المنصة صلاحية منصّية.',
        );
    }

    /** @return array<string, mixed> */
    private function payload(BillingSettings $settings): array
    {
        return [
            'operating_fee_minor' => [
                'individual' => $settings->operatingFeeMinor(ClassSessionType::Individual),
                'group' => $settings->operatingFeeMinor(ClassSessionType::Group),
            ],
            'gateway_fee_bps' => $settings->gatewayFeeBps(),
            'gateway_fixed_fee_minor' => $settings->gatewayFixedFeeMinor(),
            'currency' => $settings->currency(),
            // The two escrow guards (Q-11) are operated from the same screen:
            // both are platform numbers, and both are read on the purchase path.
            'stop_selling_after_days' => $settings->stopSellingAfterDays(),
            'max_unredeemed_credits' => $settings->maxUnredeemedCredits(),
        ];
    }
}
