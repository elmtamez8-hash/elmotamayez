<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| The refusal must not tell a guesser what exists (T074 · api.md).
|
| ⚠️ `code` IS UNIQUE PLATFORM-WIDE WHILE `workspace_id` NARROWS WHO MAY SPEND
| IT, so a distinct answer for «not yours» tells whoever typed it that a real
| code exists at another teacher's — and this is the one endpoint in the product
| whose entire purpose is to be guessed at, which is why it carries the tightest
| rate limiter in the file. Same rule as the payment webhook's uniform `202`.
|
| «Expired» and «used up» ARE stated, deliberately: by then the caller has proved
| they hold a real code in their own scope, and the sentence tells them something
| they can act on rather than something they can enumerate.
*/
beforeEach(function (): void {
    [$this->mine, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->theirs, $this->stranger] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->mine, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->mine->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->buyer = User::factory()->create();
});

function refusalFor(string $code): string
{
    try {
        app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
            'item_uuid' => test()->item->uuid,
            'coupon_code' => $code,
        ]));
    } catch (DomainException $e) {
        return $e->getMessage();
    }

    test()->fail('the coupon was accepted');
}

it('answers a code that does not exist and one belonging elsewhere identically', function (): void {
    $foreign = Coupon::factory()->percent(50)->forWorkspace((int) $this->theirs->getKey())->create();

    expect(refusalFor('NOSUCHCODE'))->toBe(refusalFor($foreign->code));
});

it('answers an out-of-scope code the same way again', function (): void {
    $another = StoreItem::factory()->create(['workspace_id' => $this->mine->getKey()]);

    $scoped = Coupon::factory()
        ->percent(50)
        ->scopedTo(CouponScope::StoreItem, (string) $another->uuid)
        ->create();

    expect(refusalFor($scoped->code))->toBe(refusalFor('NOSUCHCODE'));
});

it('answers a switched-off code the same way', function (): void {
    // A distinct «this code is paused» would confirm the code is real, which is
    // the same disclosure by a friendlier name.
    $off = Coupon::factory()->percent(50)->create(['is_active' => false]);

    expect(refusalFor($off->code))->toBe(refusalFor('NOSUCHCODE'));
});

it('does say why for a real code in scope that has expired or run out', function (): void {
    $expired = Coupon::factory()->percent(50)->expired()->create();
    $exhausted = Coupon::factory()->percent(50)->used(max: 1, used: 1)->create();

    $unknown = refusalFor('NOSUCHCODE');

    expect(refusalFor($expired->code))->not->toBe($unknown)
        ->and(refusalFor($exhausted->code))->not->toBe($unknown)
        // And the two say different things: «expired» and «used up» are
        // different problems with different answers for the person holding one.
        ->and(refusalFor($expired->code))->not->toBe(refusalFor($exhausted->code));
});
