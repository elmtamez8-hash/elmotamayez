<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Support\ListingPageSizes;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
| Owner request 2026-10-11: how many rows each public list shows is set from
| «إعدادات المنصّة», not shipped in code.
*/

it('defaults to the sizes the lists shipped with', function (): void {
    expect(ListingPageSizes::store())->toBe(24)
        ->and(ListingPageSizes::courses())->toBe(12)
        ->and(ListingPageSizes::teachers())->toBe(12)
        ->and(ListingPageSizes::reviews())->toBe(10);
});

it('pages every public list by the stored size', function (): void {
    foreach (['store' => 5, 'courses' => 7, 'teachers' => 9] as $list => $size) {
        PlatformSettings::set(ListingPageSizes::key($list), $size);
    }

    $this->getJson('/api/v1/marketplace/store/items')->assertOk()->assertJsonPath('meta.per_page', 5);
    $this->getJson('/api/v1/marketplace/courses')->assertOk()->assertJsonPath('meta.per_page', 7);
    $this->getJson('/api/v1/marketplace/teachers')->assertOk()->assertJsonPath('meta.per_page', 9);
});

it('never lets a stored size ask for an unbounded page', function (): void {
    PlatformSettings::set(ListingPageSizes::key('reviews'), 5000);
    PlatformSettings::set(ListingPageSizes::key('store'), 0);

    expect(ListingPageSizes::reviews())->toBe(ListingPageSizes::MAX)
        ->and(ListingPageSizes::store())->toBe(1);
});

it('is edited from the platform settings page', function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(ManagePlatformSettings::class)
        ->assertFormSet(['page_size_store' => 24, 'page_size_reviews' => 10])
        ->fillForm(['page_size_store' => 12, 'page_size_courses' => 6, 'page_size_teachers' => 15, 'page_size_reviews' => 8])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ListingPageSizes::store())->toBe(12)
        ->and(ListingPageSizes::courses())->toBe(6)
        ->and(ListingPageSizes::teachers())->toBe(15)
        ->and(ListingPageSizes::reviews())->toBe(8);
});
