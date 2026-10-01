<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| The teacher's shelf over HTTP: money in major units, the product's own uploads
| (file and cover), and the platform's cut kept off the buyer's catalogue.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
});

function printedPayload(array $overrides = []): array
{
    return $overrides + [
        'kind' => 'physical',
        'title' => 'مذكّرة الكيمياء',
        'price' => '150.00',
        'stock' => 20,
        'shipping_fee' => '50',
    ];
}

it('takes the price in major units and sends it back the same way', function (): void {
    $response = $this->postJson('/api/v1/store/items', printedPayload())->assertCreated();

    $item = StoreItem::query()->where('uuid', $response->json('uuid'))->firstOrFail();

    expect((int) $item->price_minor)->toBe(15000)
        ->and((int) $item->shipping_fee_minor)->toBe(5000)
        ->and($response->json('price'))->toBe('150.00')
        ->and($response->json('shipping_fee'))->toBe('50.00');
});

it('refuses a price with more than two decimals rather than rounding it', function (): void {
    $this->postJson('/api/v1/store/items', printedPayload(['price' => '150.005']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price');
});

it('refuses to put a digital product on sale before its file is uploaded', function (): void {
    $this->postJson('/api/v1/store/items', ['kind' => 'digital', 'title' => 'ملخّص', 'price' => '40'])
        ->assertUnprocessable();

    $this->postJson('/api/v1/store/items', ['kind' => 'digital', 'title' => 'ملخّص', 'price' => '40', 'is_active' => false])
        ->assertCreated()
        ->assertJsonPath('is_active', false);
});

it('reserves an upload owned by the product itself', function (): void {
    $item = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey(), 'is_active' => false]);

    $uuid = $this->postJson("/api/v1/store/items/{$item->uuid}/file", ['filename' => 'book.pdf', 'size_bytes' => 1024])
        ->assertCreated()
        ->json('asset.uuid');

    $asset = MediaAsset::query()->where('uuid', $uuid)->firstOrFail();

    expect($asset->owner_type)->toBe(StoreItem::class)
        ->and((int) $asset->owner_id)->toBe((int) $item->getKey())
        ->and($asset->kind)->toBe(MediaKind::Document);
});

it('will not complete another product\'s or a lesson\'s file through a product', function (): void {
    $item = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $lessonFile = MediaAsset::factory()->processing()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->postJson("/api/v1/store/items/{$item->uuid}/file/{$lessonFile->uuid}/complete")->assertNotFound();
});

it('stores a cover and serves its url to the buyer', function (): void {
    Storage::fake('public');
    $item = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $url = $this->post("/api/v1/store/items/{$item->uuid}/cover", [
        'cover' => UploadedFile::fake()->image('cover.png', 900, 1200),
    ], ['Accept' => 'application/json'])->assertOk()->json('cover_url');

    $path = $item->refresh()->cover_path;

    expect($path)->not->toBeNull()
        ->and($url)->toEndWith($path);
    Storage::disk('public')->assertExists((string) $path);
});

it('keeps the platform commission off the buyer catalogue', function (): void {
    StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    expect($this->getJson('/api/v1/store/items')->json('data.0'))->toHaveKey('commission_bps');

    Sanctum::actingAs(User::factory()->create());

    $row = $this->getJson('/api/v1/store/catalogue?workspace_uuid='.$this->workspace->uuid)->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('commission_bps')->and($row)->not->toHaveKey('price');
});

it('names the product on the buyer\'s order instead of «شراء من المتجر»', function (): void {
    $item = StoreItem::factory()->physical()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'مذكّرة الفيزياء']);
    $buyer = User::factory()->create();

    app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    Sanctum::actingAs($buyer);

    expect($this->getJson('/api/v1/orders')->assertOk()->json('data.0.store_item_title'))->toBe('مذكّرة الفيزياء');
});
