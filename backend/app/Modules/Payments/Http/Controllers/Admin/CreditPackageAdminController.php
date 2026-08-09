<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\StoreCreditPackageRequest;
use App\Modules\Payments\Models\CreditPackage;
use Illuminate\Http\JsonResponse;

/**
 * The platform's package catalogue (FR-016 · FR-017).
 *
 * Sizes are rows an operator edits, never constants in code — a size that can
 * only change by shipping code is a size nobody ever tunes.
 *
 * ⚠️ DISABLING IS THE ONLY RETIREMENT, AND THERE IS NO DELETE (FR-019). Credits
 * bought from a package keep pointing at it: `credit_purchases` reads its
 * validity through that row and spec 015's books name it. Deleting one would
 * orphan purchases that have already been paid for, so a retired package is
 * `is_active = false` and stays legible for ever.
 *
 * No Action behind these three verbs, deliberately: the convention exists so a
 * business rule cannot be bypassed by a second caller, and there is no rule here
 * beyond the permission and the shape. Every rule that DOES touch a package —
 * what it costs, whether it may still be bought, what happens to credits from a
 * retired one — lives in CostPlusPricing and PurchaseCredits, where the money
 * moves.
 */
class CreditPackageAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CreditPackage::class);

        $packages = CreditPackage::query()
            ->orderBy('sort_order')
            ->orderBy('credits')
            ->get()
            ->map(fn (CreditPackage $package): array => $this->payload($package));

        return response()->json($packages);
    }

    public function store(StoreCreditPackageRequest $request): JsonResponse
    {
        $this->authorize('create', CreditPackage::class);

        $package = CreditPackage::query()->create($request->validated());

        // Refreshed, not returned as built: `is_active` and `sort_order` have
        // their defaults in the SCHEMA, so an unrefreshed model answers null for
        // both — and a client reading `is_active: null` renders a package it has
        // no way to know is live.
        return response()->json($this->payload($package->refresh()), 201);
    }

    public function update(StoreCreditPackageRequest $request, string $uuid): JsonResponse
    {
        $package = CreditPackage::query()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $package);

        $package->fill($request->validated())->save();

        return response()->json($this->payload($package));
    }

    /**
     * ⚠️ No price, on the admin surface either.
     *
     * Not an omission for brevity: there is no price to send. A package has a
     * size and a session type, and what it costs depends on which course it is
     * bought against — see CostPlusPricing.
     *
     * @return array<string, mixed>
     */
    private function payload(CreditPackage $package): array
    {
        return [
            'uuid' => $package->uuid,
            'name' => $package->name,
            'credits' => $package->credits,
            'session_type' => $package->session_type->value,
            'validity_days' => $package->validity_days,
            'is_active' => $package->is_active,
            'sort_order' => $package->sort_order,
        ];
    }
}
