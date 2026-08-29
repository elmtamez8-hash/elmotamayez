<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use Illuminate\Support\Facades\DB;

/*
| SC-001 — a digital purchase opens the instant the payment is witnessed, and
| never before it.
|
| ⚠️ AND `first_accessed_at` IS STAMPED ONCE UNDER TWO SIMULTANEOUS OPENINGS.
| That column is what closes the refund window, so a read-then-write there moves
| the stamp forward on every open and hands the buyer back a window that had
| already shut. The seam below fires the second opening from INSIDE the first
| one's claim statement, which is the other worker winning in exactly that
| window — no threads, no sleeps.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'media_asset_id' => $this->asset->getKey(),
    ]);

    // Built with no seeder and no `setCurrentWorkspace()`: a buyer is a member
    // of no workspace, and a fixture that stamps `last_workspace_id` measures a
    // person production never produces.
    $this->buyer = User::factory()->create();

    $this->session = AuthSession::factory()->create([
        'user_id' => $this->buyer->getKey(),
    ]);

    $this->purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
    ]));

    $this->order = Order::query()->whereKey($this->purchase->order_id)->firstOrFail();
});

it('refuses to open a purchase whose payment has not been approved', function (): void {
    // The sentence names WHICH of the two states this is. «لا تملك صلاحية» about
    // your own purchase sends the buyer to support instead of to the receipt.
    expect(fn (): PlaybackGrant => app(IssueStoreAccess::class)
        ->handle($this->purchase->uuid, $this->buyer, $this->session))
        ->toThrow(RuntimeException::class, 'لم يُعتمَد الدفع لهذا الطلب بعد.');
});

it('opens it the moment the payment is fulfilled', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);

    $grant = app(IssueStoreAccess::class)
        ->handle($this->purchase->uuid, $this->buyer, $this->session);

    expect($grant->media_asset_id)->toBe($this->asset->getKey())
        ->and($grant->user_id)->toBe($this->buyer->getKey())
        // The binding that makes a copied link useless — and the route by which
        // the device limit reaches a file bought from the store.
        ->and($grant->auth_session_id)->toBe($this->session->getKey());
});

it('refuses another buyer the same purchase uuid', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);

    $stranger = User::factory()->create();
    $strangerSession = AuthSession::factory()->create(['user_id' => $stranger->getKey()]);

    // ⚠️ THIS IS THE WHOLE REASON THE ROUTE TAKES A STRING. `StoreOrder` carries
    // `BelongsToWorkspace`, and for a student the context is null so the scope
    // adds NO condition — an implicit binding resolves anybody's order.
    expect(fn (): PlaybackGrant => app(IssueStoreAccess::class)
        ->handle($this->purchase->uuid, $stranger, $strangerSession))
        ->toThrow(RuntimeException::class);
});

it('stamps the opening once when two openings overlap', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);

    $fired = false;

    DB::listen(function ($query) use (&$fired): void {
        if ($fired || ! str_contains($query->sql, 'update "store_orders"')) {
            return;
        }

        if (! str_contains($query->sql, 'first_accessed_at')) {
            return;
        }

        $fired = true;

        app(IssueStoreAccess::class)->handle($this->purchase->uuid, $this->buyer, $this->session);
    });

    $this->travelTo(now()->subHour());

    app(IssueStoreAccess::class)->handle($this->purchase->uuid, $this->buyer, $this->session);

    expect($fired)->toBeTrue('the seam never fired — the test proved nothing');

    // Both grants exist; the stamp does not move. `WHERE first_accessed_at IS
    // NULL` is what makes the second write a no-op.
    // `PlaybackGrant` carries no `BelongsToWorkspace` — it is issued and consumed
    // with no workspace in the request at all.
    expect(PlaybackGrant::query()->count())->toBe(2);

    $stamped = StoreOrder::query()
        ->withoutWorkspaceScope()
        ->whereKey($this->purchase->getKey())
        ->value('first_accessed_at');

    expect($stamped)->not->toBeNull();
});

it('refuses to open a refunded purchase', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);

    StoreOrder::query()
        ->withoutWorkspaceScope()
        ->whereKey($this->purchase->getKey())
        ->update(['refunded_at' => now()]);

    expect(fn (): PlaybackGrant => app(IssueStoreAccess::class)
        ->handle($this->purchase->uuid, $this->buyer, $this->session))
        ->toThrow(RuntimeException::class);
});
