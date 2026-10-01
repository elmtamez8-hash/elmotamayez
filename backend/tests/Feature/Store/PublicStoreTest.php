<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The public store (`/marketplace/store/*`): every listed teacher's products,
| for a guest or anybody signed in.
|
| ⚠️ TWO WORKSPACES AND A SIGNED-IN READER FROM A THIRD, never a guest alone. The
| workspace scope is inert for a guest, so a guest-only test passes with no guard
| at all; the reader it fails is a signed-in student whose context ANDs their own
| workspace onto every query (docs/gotchas/tenancy.md).
*/

beforeEach(function (): void {
    // Reference data the suite seeds once per process; made here only if absent.
    $this->chemistry = Subject::query()->where('slug', 'chemistry')->first() ?? Subject::factory()->create(['slug' => 'chemistry']);
    $this->physics = Subject::query()->where('slug', 'physics')->first() ?? Subject::factory()->create(['slug' => 'physics']);

    $this->academy = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->academy);

    $this->other = marketplaceWorkspace('Other');
    $this->otherTeacher = marketplaceTeacher($this->other);

    $this->hidden = marketplaceWorkspace('Hidden', participates: false);
    $this->hiddenTeacher = marketplaceTeacher($this->hidden);

    $this->book = storeProduct($this->academy, $this->teacher, ['title' => 'مذكّرة الكيمياء', 'price_minor' => 15000, 'subject_id' => $this->chemistry->getKey()], physical: true);
    $this->pdf = storeProduct($this->academy, $this->teacher, ['title' => 'ملخّص الفيزياء', 'price_minor' => 5000, 'subject_id' => $this->physics->getKey()]);
    $this->foreign = storeProduct($this->other, $this->otherTeacher, ['title' => 'كتاب آخر', 'price_minor' => 9000]);

    // Each of these must stay off the public store.
    $this->draft = storeProduct($this->academy, $this->teacher, ['title' => 'مسوّدة', 'is_active' => false]);
    $this->unlisted = storeProduct($this->hidden, $this->hiddenTeacher, ['title' => 'خارج السوق']);
    $this->ownerless = storeProduct($this->academy, null, ['title' => 'بلا مدرّس']);
});

function storeProduct(Workspace $workspace, ?TeacherProfile $teacher, array $attributes = [], bool $physical = false): StoreItem
{
    $factory = $physical ? StoreItem::factory()->physical() : StoreItem::factory();

    return $factory->create($attributes + [
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $teacher?->getKey(),
    ]);
}

/**
 * Every string key in a nested payload — `PublicExposureTest`'s walker, which a
 * test in another file cannot call (a Pest helper is only loaded with its file).
 *
 * @return list<string>
 */
function storePayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...storePayloadKeys($child)];
    }

    return $keys;
}

/** @return list<string> */
function publicTitles(): array
{
    return collect(test()->getJson('/api/v1/marketplace/store/items')->assertOk()->json('data'))
        ->pluck('title')->sort()->values()->all();
}

it('lists every listed teacher\'s products and nothing else', function (): void {
    $this->asGuest();

    expect(publicTitles())->toBe(collect(['مذكّرة الكيمياء', 'ملخّص الفيزياء', 'كتاب آخر'])->sort()->values()->all());
});

it('lists the same products to a signed-in student of another workspace', function (): void {
    $guest = (function () {
        $this->asGuest();

        return publicTitles();
    })->call($this);

    // A student whose context resolves to a THIRD workspace — the reader a
    // scoped query would hand an empty page.
    [$third] = $this->createWorkspaceWithOwner(['name' => 'Third']);
    $student = User::factory()->create(['last_workspace_id' => $third->getKey()]);
    Sanctum::actingAs($student);

    expect(publicTitles())->toBe($guest);
    expect($this->getJson('/api/v1/marketplace/store/items/'.$this->book->uuid)->assertOk()->json('teacher.uuid'))
        ->toBe($this->teacher->uuid);
});

it('answers 404 for a product the public store does not show', function (): void {
    $this->asGuest();

    foreach ([$this->draft, $this->unlisted, $this->ownerless] as $item) {
        $this->getJson('/api/v1/marketplace/store/items/'.$item->uuid)->assertNotFound();
    }

    $this->getJson('/api/v1/marketplace/store/items/'.$this->pdf->uuid)
        ->assertOk()
        ->assertJsonPath('title', 'ملخّص الفيزياء');
});

it('filters by teacher, subject and kind, and sorts by price', function (): void {
    $this->asGuest();

    $titles = fn (string $query): array => collect($this->getJson('/api/v1/marketplace/store/items?'.$query)->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles('teacher='.$this->otherTeacher->uuid))->toBe(['كتاب آخر'])
        ->and($titles('subject=chemistry'))->toBe(['مذكّرة الكيمياء'])
        ->and($titles('kind=physical'))->toBe(['مذكّرة الكيمياء'])
        ->and($titles('sort=price_asc'))->toBe(['ملخّص الفيزياء', 'كتاب آخر', 'مذكّرة الكيمياء'])
        ->and($titles('sort=price_desc'))->toBe(['مذكّرة الكيمياء', 'كتاب آخر', 'ملخّص الفيزياء']);

    $this->getJson('/api/v1/marketplace/store/items?kind=video')->assertUnprocessable();
});

it('offers only teachers and subjects that have a listed product', function (): void {
    $this->asGuest();

    $facets = $this->getJson('/api/v1/marketplace/store/facets')->assertOk()->json();

    expect(collect($facets['teachers'])->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$this->teacher->uuid, $this->otherTeacher->uuid])->sort()->values()->all())
        ->and(collect($facets['subjects'])->pluck('slug')->sort()->values()->all())->toBe(['chemistry', 'physics'])
        ->and($facets['total'])->toBe(3);
});

it('publishes only allowlisted keys, and says availability rather than stock', function (): void {
    $this->asGuest();

    $allowed = [
        ...PublicFieldAllowlist::STORE_ITEM,
        ...PublicFieldAllowlist::STORE_ITEM_NESTED,
        ...PublicFieldAllowlist::STORE_FACETS,
        // Laravel's own pagination envelope.
        'data', 'links', 'meta', 'first', 'last', 'prev', 'next', 'current_page', 'from', 'last_page',
        'path', 'per_page', 'to', 'total', 'url', 'label', 'active', 'page',
    ];

    $payloads = [
        'list' => $this->getJson('/api/v1/marketplace/store/items')->json(),
        'detail' => $this->getJson('/api/v1/marketplace/store/items/'.$this->book->uuid)->json(),
        'facets' => $this->getJson('/api/v1/marketplace/store/facets')->json(),
    ];

    foreach ($payloads as $label => $payload) {
        foreach (array_unique(storePayloadKeys($payload)) as $key) {
            expect(in_array($key, $allowed, true))->toBeTrue("{$label}.{$key} is on no allowlist")
                ->and(in_array($key, PublicFieldAllowlist::FORBIDDEN, true))->toBeFalse("{$label}.{$key} is forbidden");
        }
    }

    expect(storePayloadKeys($payloads['list']))->not->toContain('stock')->not->toContain('commission_bps')
        ->and($payloads['detail']['is_available'])->toBeTrue();
});

it('links a product to its course only when the course has a public page, and filters by course', function (): void {
    $this->asGuest();

    [$public, $private] = app(WorkspaceContext::class)->forWorkspace($this->academy, fn (): array => [
        Course::factory()->published()->create(['workspace_id' => $this->academy->getKey(), 'created_by' => $this->teacher->user_id]),
        Course::factory()->create(['workspace_id' => $this->academy->getKey(), 'created_by' => $this->teacher->user_id]),
    ]);

    $linked = storeProduct($this->academy, $this->teacher, ['title' => 'مع كورس عام', 'course_id' => $public->getKey()]);
    $hiddenCourse = storeProduct($this->academy, $this->teacher, ['title' => 'مع كورس خاص', 'course_id' => $private->getKey()]);

    expect($this->getJson('/api/v1/marketplace/store/items/'.$linked->uuid)->json('course.uuid'))->toBe($public->uuid)
        // The product is still sold; only the link to a page that would 404 is dropped.
        ->and($this->getJson('/api/v1/marketplace/store/items/'.$hiddenCourse->uuid)->assertOk()->json('course'))->toBeNull()
        ->and(collect($this->getJson('/api/v1/marketplace/store/items?course='.$public->uuid)->json('data'))->pluck('title')->all())
        ->toBe(['مع كورس عام']);
});

it('answers a signed-in reader whose context resolved to their own workspace, facets included', function (): void {
    [$third] = $this->createWorkspaceWithOwner(['name' => 'Third']);
    $student = User::factory()->create(['last_workspace_id' => $third->getKey()]);
    Sanctum::actingAs($student);

    $titles = collect($this->getJson('/api/v1/marketplace/store/items')->assertOk()->json('data'))->pluck('title');
    $facets = $this->getJson('/api/v1/marketplace/store/facets')->assertOk()->json();

    // The precondition the test exists for: the reader's context is NOT null —
    // a scoped query would have ANDed the third workspace onto every read.
    expect(app(WorkspaceContext::class)->id())->toBe((int) $third->getKey())
        ->and($titles)->toHaveCount(3)
        ->and($facets['total'])->toBe(3);
});

it('refuses a purchase by the shop\'s own staff, and sells to a student of another workspace', function (): void {
    $staff = User::query()->findOrFail($this->teacher->user_id);
    $this->academy->members()->syncWithoutDetaching([$staff->getKey() => ['role' => Roles::TEACHER]]);

    expect(fn () => app(PurchaseStoreItem::class)->handle($staff, PurchaseData::fromArray(['item_uuid' => $this->pdf->uuid])))
        ->toThrow(DomainException::class, 'لا يمكنك شراء منتج من متجرك.');

    $buyer = User::factory()->create();

    expect(app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray(['item_uuid' => $this->pdf->uuid]))->uuid)
        ->not->toBeNull();
});
