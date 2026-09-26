<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Actions\MintPlaybackGrant;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;

/*
| Opening a file and refunding it race over ONE row, and each side used to read
| the other's column outside its own claim: the open read `refunded_at`, minted,
| then stamped `first_accessed_at` on `first_accessed_at IS NULL` alone; the
| refund read `first_accessed_at`, then claimed on `refunded_at IS NULL` alone.
| Interleaved, BOTH won — the buyer held a grant to the book AND their money.
|
| ⚠️ A SEQUENTIAL TEST CANNOT SEE THIS (docs/gotchas/testing.md): «open, then
| refund» is refused by the refund's own pre-check whether or not the claim names
| the other column. So each case opens the window single-threaded — the competing
| write is performed from INSIDE the first side, at exactly the instant the other
| runner would win.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'media_asset_id' => $asset->getKey(),
    ]);

    $this->buyer = User::factory()->create();
    $this->session = AuthSession::factory()->create(['user_id' => $this->buyer->getKey()]);

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
    ]));

    app(ApproveOrder::class)->handle(Order::query()->whereKey($purchase->order_id)->firstOrFail(), $this->owner);

    $this->purchase = $purchase->refresh();
});

/** Both outcomes at once is the defect; exactly one of them is the fix. */
function raceOutcome(StoreOrder $purchase, User $buyer): array
{
    return [
        'granted' => PlaybackGrant::query()->where('user_id', $buyer->getKey())->exists(),
        'refunded' => $purchase->refresh()->refunded_at !== null,
    ];
}

it('never grants AND refunds when the refund lands while the file is being opened', function (): void {
    // The refund lands during the mint — after the open's own check of
    // `refunded_at`, before its stamp used to be written.
    $refund = app(RefundStorePurchase::class);
    $purchaseUuid = $this->purchase->uuid;
    $buyer = $this->buyer;

    app()->bind(MintPlaybackGrant::class, fn () => new class($refund, $purchaseUuid, $buyer) extends MintPlaybackGrant
    {
        public function __construct(
            private readonly RefundStorePurchase $refund,
            private readonly string $purchaseUuid,
            private readonly User $buyer,
        ) {}

        public function handle(MediaAsset $asset, User $viewer, AuthSession $session, ?string $ipHash = null): PlaybackGrant
        {
            try {
                $this->refund->handle($this->purchaseUuid, $this->buyer);
            } catch (DomainException) {
                // The loser of the race is refused; that is the fixed outcome.
            }

            return parent::handle($asset, $viewer, $session, $ipHash);
        }
    });

    try {
        app(IssueStoreAccess::class)->handle($this->purchase->uuid, $this->buyer, $this->session);
    } catch (RuntimeException) {
        // A refusal of the open is also a legal single outcome.
    }

    $outcome = raceOutcome($this->purchase, $this->buyer);

    expect($outcome['granted'] && $outcome['refunded'])->toBeFalse()
        ->and($outcome['granted'] || $outcome['refunded'])->toBeTrue();
});

it('never refunds AND grants when the file is opened between the refund check and its claim', function (): void {
    // The open lands the instant the refund has READ the purchase — its
    // `first_accessed_at` is null in hand, and the claim has not run yet.
    $opened = false;

    StoreOrder::retrieved(function () use (&$opened): void {
        if ($opened) {
            return;
        }

        $opened = true;

        app(IssueStoreAccess::class)->handle($this->purchase->uuid, $this->buyer, $this->session);
    });

    try {
        app(RefundStorePurchase::class)->handle($this->purchase->uuid, $this->buyer);
    } catch (DomainException) {
        // The refund lost; that is the fixed outcome.
    }

    $outcome = raceOutcome($this->purchase, $this->buyer);

    expect($opened)->toBeTrue()
        ->and($outcome['granted'])->toBeTrue()
        ->and($outcome['refunded'])->toBeFalse()
        ->and(Order::query()->whereKey($this->purchase->order_id)->value('status'))->toBe('approved');
});
