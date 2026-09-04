<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Models\PlatformSetting;
use App\Modules\Tenancy\Support\PlatformSettings;
use Database\Seeders\PlatformSettingsSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * FR-022 asks for an *adjustable* limit. A constant in a config file is
 * adjustable only by shipping code, which means nobody ever adjusts it.
 */
it('falls back to config when nothing is stored', function (): void {
    // The application has to run correctly against a database with no settings
    // seeded — a row is an override, never a requirement.
    expect(PlatformSettings::get('auth.device_limits'))
        ->toBe(config('media.device_limits'));
});

it('prefers a stored value and forgets the cache on write', function (): void {
    expect(PlatformSettings::get('auth.device_limits'))->toBe(['student' => 1]);

    PlatformSettings::set('auth.device_limits', ['student' => 3]);

    // No deploy, no cache flush by hand: the next read sees it.
    expect(PlatformSettings::get('auth.device_limits'))->toBe(['student' => 3]);
});

it('records who changed it', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    PlatformSettings::set('media.grant_ttl_seconds', 120, (int) $admin->getKey());

    expect(PlatformSetting::query()->find('media.grant_ttl_seconds')?->updated_by_user_id)
        ->toBe($admin->getKey());
});

it('is reachable by a platform admin and nobody else', function (): void {
    // Not a tenant permission: the device limit governs an account that enrols
    // with many teachers, so no single teacher may decide it.
    $teacher = User::factory()->create(['is_super_admin' => false]);
    Auth::login($teacher);

    expect(ManagePlatformSettings::canAccess())->toBeFalse();

    $admin = User::factory()->create(['is_super_admin' => true]);
    Auth::login($admin);

    expect(ManagePlatformSettings::canAccess())->toBeTrue();
});

/**
 * ⚠️ EVERY VALUE IN `KEYS` IS A CONFIG PATH, AND ONE OF THEM POINTED AT A FILE
 * THAT DOES NOT EXIST — `subscription.` against `config/subscriptions.php`.
 *
 * `config()` answers NULL for a path with no file behind it, and
 * `platform_settings.value` is NOT NULL: `db:seed` therefore died on the THIRD
 * of ten seeders on MySQL, and the six reference catalogues after it in
 * `DatabaseSeeder` — credit packages, gamification actions, data categories,
 * processors, regions, taxonomy — never ran at all. Every one of those is a
 * catalogue this codebase has already been bitten by for being empty.
 *
 * It could not be seen from the reading side: `ExpireSubscriptionsJob` passes
 * its own `config('subscriptions.…')` fallback explicitly, so the feature was
 * correct throughout and only the seeder ever asked the map for a default. It
 * was found on 2026-09-01 by running the seeder against production.
 *
 * This walks the map itself rather than asserting one key, because the typo is
 * a per-row mistake and the next entry added is as able to carry it.
 */
it('resolves a non-null default for every settings key', function (): void {
    $unresolved = [];

    foreach (PlatformSettings::KEYS as $key => $configPath) {
        if (config($configPath) === null) {
            $unresolved[$key] = $configPath;
        }
    }

    expect($unresolved)->toBe([]);
});

/**
 * The half above proves the map; this proves the seeder that reads it, which is
 * the thing that actually broke. A `firstOrCreate` handed an empty attribute
 * array writes NULL into a NOT NULL column — and on SQLite, where every test in
 * this repository runs, that is the same violation MySQL raised in production.
 */
it('seeds a row for every settings key', function (): void {
    PlatformSetting::query()->delete();

    $this->seed(PlatformSettingsSeeder::class);

    expect(PlatformSetting::query()->count())->toBe(count(PlatformSettings::KEYS));
    expect(PlatformSetting::query()->whereNull('value')->count())->toBe(0);
});

/**
 * ⛔ حصّةُ المنصّةِ كانت أرقاماً بلا شاشة.
 *
 * `BillingPricingController` موجودٌ بمسارَيه وبصلاحيّةِ `billing.pricing.manage`،
 * وتعليقُه يقولُ إنّ حارسَي الأمانةِ «يُداران من الشاشةِ نفسِها» — ولا شاشةَ في
 * المنتَجِ كلِّه تنادِيه. فبقيَت الأربعةُ على أصفارِها المبذورة، وكلُّ بيعةٍ على
 * الإنتاجِ سُجِّلَت بحصّةٍ صفر (قِيسَ 2026-09-04: شراءُ الأرصدةِ الوحيدُ برسومِ
 * تشغيلٍ وبوّابةٍ صفرَين على ‏٤٨٠ ر.ق).
 */
it('writes the platform margin where the pricing engine reads it', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    Auth::login($admin);

    Livewire::test(ManagePlatformSettings::class)
        ->fillForm([
            'operating_fee_individual' => 500,
            'operating_fee_group' => 200,
            'gateway_fee_bps' => 250,
            'gateway_fixed_fee_minor' => 100,
            'stop_selling_after_days' => 45,
            'max_unredeemed_credits' => 30,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    /*
    | ⚠️ التوكيدُ عبرَ {@see BillingSettings}، لا على مفاتيحِ `platform_settings`.
    | مفتاحٌ بهجاءٍ ثانٍ يكتبُ صفّاً لا يقرؤه أحد: الشاشةُ تعرضُ ما حُفِظ، والمحرّكُ
    | يُسعِّرُ بالصفرِ كما كان — وهو عطلٌ لا يُظهِرُه توكيدٌ على الصفِّ نفسِه.
    */
    $billing = app(BillingSettings::class);

    expect($billing->operatingFeeMinor(ClassSessionType::Individual))->toBe(500)
        ->and($billing->operatingFeeMinor(ClassSessionType::Group))->toBe(200)
        ->and($billing->gatewayFeeBps())->toBe(250)
        ->and($billing->gatewayFixedFeeMinor())->toBe(100)
        ->and($billing->stopSellingAfterDays())->toBe(45)
        ->and($billing->maxUnredeemedCredits())->toBe(30);
});

it('opens the pricing form on the numbers the engine is actually using', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    Auth::login($admin);

    /*
    | ⚠️ `stop_selling_after_days` **لا صفَّ له** — يرتدُّ إلى `config/billing.php`.
    | نموذجٌ يقرأُ المفتاحَ خامّاً يفتحُ بخانةٍ فارغةٍ عن رقمٍ يعملُ فعلاً، فيحفظُها
    | المشغِّلُ صفراً ظانّاً أنّه لم يمسَّ شيئاً.
    */
    expect(PlatformSetting::query()->find('billing.stop_selling_after_days'))->toBeNull();

    Livewire::test(ManagePlatformSettings::class)
        ->assertFormSet([
            'stop_selling_after_days' => config('billing.stop_selling_after_days', 60),
            'max_unredeemed_credits' => config('billing.max_unredeemed_credits', 24),
        ]);
});
