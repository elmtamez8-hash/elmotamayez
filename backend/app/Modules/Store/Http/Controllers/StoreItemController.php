<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\SaveStoreItem;
use App\Modules\Store\Data\StoreItemData;
use App\Modules\Store\Http\Requests\SaveStoreItemRequest;
use App\Modules\Store\Http\Resources\StoreItemResource;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The teacher's side of the store (spec 011 · US1 · FR-001 · FR-002).
 *
 * ⚠️ ROUTE-MODEL BINDING IS SAFE HERE AND ONLY HERE. The reader is a member of
 * the workspace, so `WorkspaceContext::id()` resolves and `WorkspaceScope`
 * filters — a foreign uuid 404s before any policy runs. On the STUDENT routes it
 * is the opposite: a student belongs to no workspace, the scope adds no
 * condition, and an implicit binding resolves anybody's row.
 */
class StoreItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StoreItem::class);

        $items = StoreItem::query()
            // ⚠️ EAGER LOADED, AND THE FIELD IS ASSERTED AS WELL AS THE COST.
            // Dropping this makes the page one query CHEAPER and the course name
            // absent, so a budget test measuring queries alone reads the
            // regression as an improvement.
            ->with('course:id,uuid,title')
            ->orderByDesc('id')
            ->paginate(20);

        return StoreItemResource::collection($items);
    }

    public function store(SaveStoreItemRequest $request, SaveStoreItem $save): JsonResponse
    {
        $this->authorize('create', StoreItem::class);

        $item = $save->handle(
            StoreItemData::fromArray($request->validated()),
            $this->workspaceId(),
        );

        return (new StoreItemResource($item))->response()->setStatusCode(201);
    }

    public function update(SaveStoreItemRequest $request, StoreItem $item, SaveStoreItem $save): StoreItemResource
    {
        $this->authorize('update', $item);

        return new StoreItemResource($save->handle(
            StoreItemData::fromArray($request->validated()),
            $this->workspaceId(),
            $item,
        ));
    }

    private function workspaceId(): int
    {
        $id = app(WorkspaceContext::class)->id();

        if ($id === null) {
            // A teacher always has one. Reaching here means the panel or a token
            // resolved no workspace, and writing the row with a null would give
            // the product to nobody — the silent-empty-column defect this module
            // guards against everywhere else.
            throw new DomainException('تعذّر تحديد مكان العمل.');
        }

        return $id;
    }
}
