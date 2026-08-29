<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Actions\SaveStoreItem;
use App\Modules\Store\Data\StoreItemData;
use App\Modules\Store\Models\StoreItem;

/*
| ⚠️ `exists:media_assets,id` IS A RAW QUERY THAT IGNORES THE GLOBAL SCOPE, so the
| obvious validation rule lets one teacher attach ANOTHER TEACHER'S video to a
| product and sell it. `media_assets` is workspace-partitioned; the rule is not.
|
| The Action re-checks anyway, because validation is one of four entrances —
| `SeedCommand` runs every seeder inside `Model::unguarded()`, and the panel and
| the importer reach the Action with no form behind them at all.
*/

beforeEach(function (): void {
    [$this->mine, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->theirs, $this->stranger] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->mine, $this->owner);

    $this->myAsset = MediaAsset::factory()->create(['workspace_id' => $this->mine->getKey()]);
    $this->theirAsset = MediaAsset::factory()->create(['workspace_id' => $this->theirs->getKey()]);
});

it('attaches a file from the teacher own workspace', function (): void {
    $item = app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray([
            'kind' => 'digital',
            'title' => 'مذكّرة',
            'price_minor' => 5000,
            'media_asset_uuid' => $this->myAsset->uuid,
        ]),
        (int) $this->mine->getKey(),
    );

    // The positive control. Without it every refusal below is satisfied by a
    // branch that refuses everything.
    expect($item->media_asset_id)->toBe($this->myAsset->getKey());
});

it('refuses a file belonging to another teacher', function (): void {
    expect(fn (): StoreItem => app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray([
            'kind' => 'digital',
            'title' => 'مذكّرة',
            'price_minor' => 5000,
            'media_asset_uuid' => $this->theirAsset->uuid,
        ]),
        (int) $this->mine->getKey(),
    ))->toThrow(DomainException::class, 'الملف المختار غير موجود في مساحتك.');
});

it('answers a missing file and a stolen one identically', function (): void {
    // A distinct message for the second case is an oracle telling a teacher
    // which uuids are real.
    $missing = null;
    $foreign = null;

    try {
        app(SaveStoreItem::class)->handle(
            StoreItemData::fromArray([
                'kind' => 'digital',
                'title' => 'مذكّرة',
                'price_minor' => 5000,
                'media_asset_uuid' => '00000000-0000-4000-8000-000000000000',
            ]),
            (int) $this->mine->getKey(),
        );
    } catch (DomainException $e) {
        $missing = $e->getMessage();
    }

    try {
        app(SaveStoreItem::class)->handle(
            StoreItemData::fromArray([
                'kind' => 'digital',
                'title' => 'مذكّرة',
                'price_minor' => 5000,
                'media_asset_uuid' => $this->theirAsset->uuid,
            ]),
            (int) $this->mine->getKey(),
        );
    } catch (DomainException $e) {
        $foreign = $e->getMessage();
    }

    expect($missing)->not->toBeNull()->and($foreign)->toBe($missing);
});

it('enforces the type rules in the action, not only in the form request', function (): void {
    // `SeedCommand` runs inside `Model::unguarded()` and the panel has no form,
    // so a rule that lived only in validation would be walked around by three of
    // its four callers.
    expect(fn (): StoreItem => app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray(['kind' => 'digital', 'title' => 'x', 'price_minor' => 5000]),
        (int) $this->mine->getKey(),
    ))->toThrow(DomainException::class, 'المنتج الرقمي يحتاج ملفاً مرفوعاً.');

    expect(fn (): StoreItem => app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray(['kind' => 'physical', 'title' => 'x', 'price_minor' => 5000]),
        (int) $this->mine->getKey(),
    ))->toThrow(DomainException::class);

    expect(fn (): StoreItem => app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray([
            'kind' => 'digital',
            'title' => 'x',
            'price_minor' => 0,
            'media_asset_uuid' => $this->myAsset->uuid,
        ]),
        (int) $this->mine->getKey(),
    ))->toThrow(DomainException::class);
});

it('never gives a digital item a stock number', function (): void {
    $item = app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray([
            'kind' => 'digital',
            'title' => 'مذكّرة',
            'price_minor' => 5000,
            'media_asset_uuid' => $this->myAsset->uuid,
            // Submitted, and deliberately ignored: `null` is what «cannot run
            // out» means, and a zero here would report «نفد» for ever.
            'stock' => 7,
            'shipping_fee_minor' => 900,
        ]),
        (int) $this->mine->getKey(),
    );

    expect($item->stock)->toBeNull()->and($item->shipping_fee_minor)->toBeNull();
});
