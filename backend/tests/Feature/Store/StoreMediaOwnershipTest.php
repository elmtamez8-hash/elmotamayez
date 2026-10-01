<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Actions\SaveStoreItem;
use App\Modules\Store\Data\StoreItemData;
use App\Modules\Store\Models\StoreItem;

/*
| A digital product's file is the product's OWN upload, and finished.
|
| ⚠️ IT USED TO BE «ANY ASSET OF THE WORKSPACE». A lesson's paid video could be
| typed in by uuid and sold as a product, and a half-uploaded file put on sale.
| Since 2026-10-01 only an asset uploaded through `/store/items/{item}/file`
| (owner = that item) and `Ready` is accepted — so a new digital item is saved
| hidden first, then given its file, then put on sale.
*/

beforeEach(function (): void {
    [$this->mine, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->theirs, $this->stranger] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->mine, $this->owner);
});

function shelfSave(array $payload, ?StoreItem $item = null): StoreItem
{
    return app(SaveStoreItem::class)->handle(
        StoreItemData::fromArray($payload + ['title' => 'مذكّرة', 'price' => '50.00']),
        (int) test()->mine->getKey(),
        $item,
    );
}

/** A hidden digital item with no file yet — the first step of the form. */
function hiddenDigital(): StoreItem
{
    return shelfSave(['kind' => 'digital', 'is_active' => false]);
}

function fileOf(StoreItem $item, array $overrides = []): MediaAsset
{
    return MediaAsset::factory()->create($overrides + [
        'workspace_id' => $item->workspace_id,
        'owner_type' => StoreItem::class,
        'owner_id' => $item->getKey(),
    ]);
}

it('puts a digital item on sale once its own ready file is attached', function (): void {
    $item = hiddenDigital();
    $file = fileOf($item);

    $live = shelfSave(['kind' => 'digital', 'media_asset_uuid' => $file->uuid, 'is_active' => true], $item);

    // The positive control: without it every refusal below is satisfied by a
    // branch that refuses everything.
    expect($live->media_asset_id)->toBe($file->getKey())->and($live->is_active)->toBeTrue();
});

it('refuses a same-workspace file that is not this item\'s upload', function (): void {
    $item = hiddenDigital();
    // A lesson's file in the SAME workspace — the case the old rule let through.
    $lessonFile = MediaAsset::factory()->create(['workspace_id' => $this->mine->getKey()]);
    $otherItemFile = fileOf(hiddenDigital());

    foreach ([$lessonFile, $otherItemFile] as $asset) {
        expect(fn (): StoreItem => shelfSave(['kind' => 'digital', 'media_asset_uuid' => $asset->uuid], $item))
            ->toThrow(DomainException::class, 'الملف المختار غير موجود عندك.');
    }
});

it('refuses its own file until the upload has finished', function (): void {
    $item = hiddenDigital();
    $file = MediaAsset::factory()->processing()->create([
        'workspace_id' => $item->workspace_id,
        'owner_type' => StoreItem::class,
        'owner_id' => $item->getKey(),
    ]);

    expect(fn (): StoreItem => shelfSave(['kind' => 'digital', 'media_asset_uuid' => $file->uuid], $item))
        ->toThrow(DomainException::class, 'الملف المختار غير موجود عندك.');
});

it('answers a missing file and another teacher\'s identically', function (): void {
    $item = hiddenDigital();
    $foreign = MediaAsset::factory()->create(['workspace_id' => $this->theirs->getKey()]);
    $messages = [];

    foreach (['00000000-0000-4000-8000-000000000000', $foreign->uuid] as $uuid) {
        try {
            shelfSave(['kind' => 'digital', 'media_asset_uuid' => $uuid], $item);
        } catch (DomainException $e) {
            $messages[] = $e->getMessage();
        }
    }

    // A distinct message for the second case is an oracle about which uuids are real.
    expect($messages)->toHaveCount(2)->and($messages[0])->toBe($messages[1]);
});

it('enforces the type rules in the action, not only in the form request', function (): void {
    expect(fn (): StoreItem => shelfSave(['kind' => 'digital']))
        ->toThrow(DomainException::class, 'ارفع ملف المنتج قبل عرضه للبيع.');

    expect(hiddenDigital()->is_active)->toBeFalse();

    expect(fn (): StoreItem => shelfSave(['kind' => 'physical']))->toThrow(DomainException::class);

    expect(fn (): StoreItem => shelfSave(['kind' => 'physical', 'stock' => 3, 'shipping_fee' => '10', 'price' => '0']))
        ->toThrow(DomainException::class);
});

it('never gives a digital item a stock number', function (): void {
    $item = shelfSave(['kind' => 'digital', 'is_active' => false, 'stock' => 7, 'shipping_fee' => '9.00']);

    expect($item->stock)->toBeNull()->and($item->shipping_fee_minor)->toBeNull();
});

it('keeps the attached file when an edit does not mention it', function (): void {
    $item = hiddenDigital();
    $file = fileOf($item);
    shelfSave(['kind' => 'digital', 'media_asset_uuid' => $file->uuid], $item);

    $edited = shelfSave(['kind' => 'digital', 'title' => 'مذكّرة المراجعة'], $item->refresh());

    expect($edited->title)->toBe('مذكّرة المراجعة')->and($edited->media_asset_id)->toBe($file->getKey());
});

it('keeps the stock and the postage when an edit does not mention them', function (): void {
    $item = shelfSave(['kind' => 'physical', 'stock' => 9, 'shipping_fee' => '15']);

    $edited = shelfSave(['kind' => 'physical', 'title' => 'كتاب المراجعة', 'price' => '60'], $item);

    expect((int) $edited->stock)->toBe(9)
        ->and((int) $edited->shipping_fee_minor)->toBe(1500)
        ->and((int) $edited->price_minor)->toBe(6000);
});
