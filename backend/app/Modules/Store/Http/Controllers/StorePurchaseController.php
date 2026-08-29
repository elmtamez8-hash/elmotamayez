<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Http\Requests\PurchaseStoreItemRequest;
use App\Modules\Store\Http\Resources\StoreItemResource;
use App\Modules\Store\Http\Resources\StoreOrderResource;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * The buyer's side of the store (spec 011 · US1 · FR-005 … FR-009).
 *
 * ⚠️ EVERY ROUTE HERE TAKES A UUID AS A STRING, NEVER A BOUND MODEL. A student
 * is a member of no workspace, so `WorkspaceContext::id()` is null and
 * `WorkspaceScope::apply()` adds NO condition — `BelongsToWorkspace` protects
 * exactly nothing on this path, and an implicit `{purchase}` would resolve any
 * buyer's order by its uuid. Ownership is resolved inside each Action, by
 * `buyer_user_id`.
 */
class StorePurchaseController extends Controller
{
    /**
     * What is on sale in one workspace.
     *
     * ⚠️ FILTERED BY `is_active` AND BY AN EXPLICIT WORKSPACE, not by the global
     * scope — which is inert for the person reading this. The workspace comes
     * from the item the student navigated from, so there is no "every product on
     * the platform" query anywhere in this file.
     */
    public function catalogue(Request $request): AnonymousResourceCollection
    {
        $workspaceUuid = (string) $request->query('workspace_uuid', '');

        $items = StoreItem::query()
            ->withoutWorkspaceScope()
            ->whereIn('workspace_id', function ($query) use ($workspaceUuid): void {
                $query->select('id')->from('workspaces')->where('uuid', $workspaceUuid);
            })
            ->where('is_active', true)
            ->with('course:id,uuid,title')
            ->orderByDesc('id')
            ->paginate(20);

        return StoreItemResource::collection($items);
    }

    public function purchases(Request $request): AnonymousResourceCollection
    {
        $buyer = $this->currentUser($request);

        $purchases = StoreOrder::query()
            ->withoutWorkspaceScope()
            ->where('buyer_user_id', $buyer->getKey())
            // Both eager loads are asserted by the budget test as fields, not
            // only as a query count: dropped, the page is cheaper and every
            // purchase lists with no title against it.
            ->with(['item:id,uuid,title,kind,price_minor,currency', 'shipment'])
            ->orderByDesc('id')
            ->paginate(20);

        return StoreOrderResource::collection($purchases);
    }

    public function store(PurchaseStoreItemRequest $request, PurchaseStoreItem $purchase): JsonResponse
    {
        $order = $purchase->handle(
            $this->currentUser($request),
            PurchaseData::fromArray($request->validated()),
        );

        return (new StoreOrderResource($order->load(['item', 'shipment'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Open a digital purchase.
     *
     * ⚠️ THE TWO FAILURES ARE ANSWERED DIFFERENTLY ON PURPOSE. `DomainException`
     * means the file is not ready — the buyer is entitled and the screen says
     * «قيد التجهيز»; `RuntimeException` means it is not theirs. One status for
     * both would send an entitled buyer to support over a transcode.
     */
    public function open(Request $request, string $purchase, IssueStoreAccess $access): JsonResponse
    {
        $user = $this->currentUser($request);
        $session = $this->currentSession($request);

        if ($session === null) {
            abort(401);
        }

        try {
            $grant = $access->handle($purchase, $user, $session, $this->ipHash($request));
        } catch (DomainException $notReady) {
            return response()->json([
                'message' => 'الملف قيد التجهيز.',
                'status' => $notReady->getMessage(),
            ], 409);
        } catch (RuntimeException $refused) {
            return response()->json(['message' => $refused->getMessage()], 403);
        }

        return response()->json(['grant_uuid' => $grant->uuid], 201);
    }

    public function refund(Request $request, string $purchase, RefundStorePurchase $refund): StoreOrderResource
    {
        return new StoreOrderResource(
            $refund->handle($purchase, $this->currentUser($request)),
        );
    }

    /**
     * The grant is bound to the AUTH SESSION, which is how the device limit
     * reaches playback: end the session and every grant it minted dies with it.
     * Spelled exactly as `PlaybackController` spells it, because two answers to
     * "which session is this" would bind grants from the two doors differently.
     */
    private function currentSession(Request $request): ?AuthSession
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        if ($tokenId === null) {
            return null;
        }

        return AuthSession::query()->active()->where('token_id', $tokenId)->first();
    }

    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();

        return $ip === null ? null : hash('sha256', $ip);
    }
}
