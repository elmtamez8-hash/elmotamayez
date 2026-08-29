<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\AdvanceShipment;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Modules\Store\Http\Requests\AdvanceShipmentRequest;
use App\Modules\Store\Http\Resources\ShipmentResource;
use App\Modules\Store\Models\Shipment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The teacher's fulfilment queue (spec 011 · US1 · FR-008).
 *
 * The address travels with the payload here and not on the buyer's own copy,
 * and the decision is made INSIDE `ShipmentResource` rather than by this file
 * remembering to ask for it — see its docblock. A flag each caller sets is a
 * flag the next caller forgets.
 */
class ShipmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Shipment::class);

        $shipments = Shipment::query()
            // The queue is ordered by what still needs doing, and the eager load
            // is asserted as a FIELD as well as a cost: dropped, the page is one
            // query cheaper and every parcel lists with no title.
            ->with('storeOrder.item:id,uuid,title')
            ->orderBy('status')
            ->orderByDesc('id')
            ->paginate(20);

        return ShipmentResource::collection($shipments)
            ->additional(['meta' => ['statuses' => ShipmentStatus::options()]]);
    }

    public function update(AdvanceShipmentRequest $request, Shipment $shipment, AdvanceShipment $advance): ShipmentResource
    {
        $this->authorize('update', $shipment);

        $moved = $advance->handle(
            $shipment,
            ShipmentStatus::from((string) $request->validated('status')),
            $request->validated('tracking_ref'),
        );

        return new ShipmentResource($moved);
    }
}
