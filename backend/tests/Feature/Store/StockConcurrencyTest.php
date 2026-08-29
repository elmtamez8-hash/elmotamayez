<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;

/*
| SC-002 — one copy, two buyers, one winner.
|
| ⚠️ AND THE MECHANISM IS PINNED, BECAUSE THE RACE CANNOT BE SEEN FROM ONE
| PROCESS. This was measured rather than assumed: `ClaimStock` was rewritten as a
| `SELECT` followed by an `UPDATE` — the exact defect — and every behavioural case
| below stayed GREEN. The reason is that a single-process seam can only fire
| between two statements that BOTH exist, and the broken version's window lies
| inside a place the correct version has no statement at all: fire the second
| runner from the fulfilment claim and the two are merely sequential, and
| sequential is a race neither version loses.
|
| So the guard is `it('claims the shelf in one conditional statement')`: the
| predicate and the write in a single UPDATE. A structural pin is weaker than a
| reproduction and stronger than a green test that proves the opposite — which is
| what the obvious version of this file was. Same reasoning as the token vector
| in `BunnyTokenVectorTest`: when the failing condition cannot be produced here,
| pin the thing that makes it impossible.
|
| ⚠️ AND `Queue::fake()` MUST NOT APPEAR IN THIS FILE. `FulfilOnPaymentApproved`
| is queued, so a bare fake swallows the listener the whole story hangs on and
| «المخزون نقص واحداً» becomes a confident claim about a column nothing wrote.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->physical(stock: 1)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    // ⚠️ TWO BUYERS BUILT WITHOUT A SEEDER AND WITHOUT `setCurrentWorkspace()`.
    // A student is a member of no workspace in production, and a fixture that
    // stamps `last_workspace_id` is measuring a person who does not exist.
    $this->first = User::factory()->create();
    $this->second = User::factory()->create();
});

function buyOneCopy(User $buyer): StoreOrder
{
    return app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
        'quantity' => 1,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));
}

it('claims the shelf in one conditional statement, never a read then a write', function (): void {
    $purchase = buyOneCopy($this->first);
    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, '"store_items"')) {
            $statements[] = $query->sql;
        }
    });

    app(FulfilStorePurchase::class)->handle($order);

    $writes = array_values(array_filter(
        $statements,
        fn (string $sql): bool => str_starts_with($sql, 'update "store_items"'),
    ));

    expect($writes)->toHaveCount(1);

    // The predicate is INSIDE the write. Split into a `SELECT … WHERE stock >= ?`
    // and an `UPDATE … SET stock = ?`, two workers both read the last copy and
    // both sell it — and no test in this file could tell you.
    expect($writes[0])->toContain('"stock" >= ?')
        // `stock = stock - ?`, not `stock = ?`. The second form carries a number
        // computed in PHP from a row that may already have moved.
        ->and($writes[0])->toContain('"stock" = "stock" -');
});

it('sells the last copy exactly once when two fulfilments overlap', function (): void {
    $firstPurchase = buyOneCopy($this->first);

    // The second buyer got in before the shelf emptied: at purchase time there
    // was still a copy, which is precisely the ordinary case — days pass before
    // a manual transfer is approved.
    $secondPurchase = buyOneCopy($this->second);

    $secondOrder = Order::query()->whereKey($secondPurchase->order_id)->firstOrFail();

    // One-shot, or it recurses. This proves the ORDERING is safe — the loser is
    // told, the shelf never goes negative — not that the claim is atomic; the
    // case above is what says that.
    $fired = false;

    DB::listen(function ($query) use (&$fired, $secondOrder): void {
        if ($fired || ! str_contains($query->sql, 'update "store_orders"')) {
            return;
        }

        if (! str_contains($query->sql, 'fulfilled_at')) {
            return;
        }

        $fired = true;

        app(FulfilStorePurchase::class)->handle($secondOrder);
    });

    app(FulfilStorePurchase::class)->handle(
        Order::query()->whereKey($firstPurchase->order_id)->firstOrFail(),
    );

    expect($fired)->toBeTrue('the seam never fired — the test proved nothing');

    // The shelf is empty and never negative.
    expect((int) $this->item->refresh()->stock)->toBe(0);

    // Exactly one of the two was delivered.
    $delivered = StoreOrder::query()
        ->withoutWorkspaceScope()
        ->whereNotNull('fulfilled_at')
        ->count();

    expect($delivered)->toBe(1);

    // And the loser is owed their money rather than left holding nothing.
    $refundDue = Order::query()
        ->withoutWorkspaceScope()
        ->where('status', 'refund_due')
        ->count();

    expect($refundDue)->toBe(1);
});

it('refuses a purchase when the shelf is already visibly empty', function (): void {
    $purchase = buyOneCopy($this->first);

    app(FulfilStorePurchase::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
    );

    // The pre-check is a courtesy, not the guard — but a buyer must not be
    // invited to pay for something that is visibly gone.
    expect(fn (): StoreOrder => buyOneCopy($this->second))
        ->toThrow(DomainException::class);
});

it('never runs out of a digital item', function (): void {
    $file = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    // ⚠️ THE BRANCH ON `kind`. `stock` is NULL here, and `stock >= 1` against
    // NULL is NULL — a claim that read the column first would report «نفد» for
    // every copy of every file, for ever, with the product perfectly fine.
    foreach ([$this->first, $this->second] as $buyer) {
        $purchase = app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
            'item_uuid' => $file->uuid,
        ]));

        app(FulfilStorePurchase::class)->handle(
            Order::query()->whereKey($purchase->order_id)->firstOrFail(),
        );

        expect($purchase->refresh()->fulfilled_at)->not->toBeNull();
    }

    expect($file->refresh()->stock)->toBeNull();
});

it('leaves an approved order fulfilled through the queued listener', function (): void {
    // The wiring, end to end and without a fake: `PaymentApproved` → the queued
    // listener → the Action. Bind one of the two events only and every card
    // payment on the platform buys a book nobody posts.
    $buyer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $purchase = buyOneCopy($buyer);
    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    app(ApproveOrder::class)->handle($order, $this->owner);

    expect($purchase->refresh()->fulfilled_at)->not->toBeNull()
        ->and((int) $this->item->refresh()->stock)->toBe(0);
});
