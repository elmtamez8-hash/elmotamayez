<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\SaveDataCategory;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Identity\Jobs\EnforceAuthSessionCapJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Spec 038 · US3 — the three numbers, from one screen.
|
| ⛔ EVERY CASE HERE MEASURES THE BEHAVIOUR, NEVER THE ROW THAT WAS WRITTEN.
| «20 was stored in the table» is green against a sweep that does not read the
| table at all, which is the only failure FR-005 is about.
|
| ⚠️ AND SC-007 IS MEASURED BY VALUE, NOT BY THE ABSENCE OF AN ERROR.
| `TestCatalogueSeeder` never calls `PlatformSettingsSeeder`, so `platform_settings`
| is empty in EVERY Feature test in this repository — which makes "it did not
| throw with nothing configured" a restatement of every other case in the suite.
| What is worth asserting is that the SHIPPED DEFAULT is the number that governs.
*/

function actAsRetentionAdmin(): User
{
    $admin = User::factory()->create(['is_super_admin' => true]);

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

/** One person, `$count` anonymised ended sessions, all `$daysAgo` old. */
function agedAnonymisedSessions(User $user, int $count, int $daysAgo): void
{
    $device = Device::factory()->create(['user_id' => $user->getKey()]);

    for ($i = 0; $i < $count; $i++) {
        $session = AuthSession::factory()->ended()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
        ]);

        DB::table('auth_sessions')->where('id', $session->getKey())->update([
            'created_at' => now()->subDays($daysAgo + 1),
            'ended_at' => now()->subDays($daysAgo)->subSeconds($i),
            'ip_hash' => null,
        ]);
    }
}

it('governs the cap with the shipped default when nothing is configured', function (): void {
    $user = User::factory()->create();
    agedAnonymisedSessions($user, 60, 400);

    /*
    | ⚠️ THE TABLE, NOT `PlatformSettings::all()`. That method returns every key in
    | the map with its RESOLVED value, config fallback included — so it holds this
    | key whether or not an operator ever set it, and asserting on it would be
    | asserting nothing. What "nothing is configured" means is that no row exists.
    */
    expect(DB::table('platform_settings')->where('key', 'auth.auth_session_cap_per_user')->exists())
        ->toBeFalse();

    EnforceAuthSessionCapJob::dispatchSync();

    // 50, the number in `config/auth_sessions.php` — not merely "no error".
    expect(AuthSession::query()->where('user_id', $user->getKey())->count())->toBe(50);
});

it('follows a cap raised from the screen', function (): void {
    actAsRetentionAdmin();

    $user = User::factory()->create();
    agedAnonymisedSessions($user, 60, 400);

    Livewire::test(ManagePlatformSettings::class)
        ->assertOk()
        // ⚠️ BELOW THE FIXTURE'S ROW COUNT ON PURPOSE. A cap above it cannot be
        // told apart from a job that deletes nothing at all.
        ->set('data.auth_session_cap_per_user', 55)
        ->call('save')
        ->assertHasNoFormErrors();

    EnforceAuthSessionCapJob::dispatchSync();

    expect(AuthSession::query()->where('user_id', $user->getKey())->count())->toBe(55);
});

it('follows a floor raised from the screen', function (): void {
    actAsRetentionAdmin();

    $user = User::factory()->create();
    // 200 days old: inside a 365-day floor, outside the shipped 180.
    agedAnonymisedSessions($user, 60, 200);

    Livewire::test(ManagePlatformSettings::class)
        ->set('data.auth_session_cap_min_age_days', 365)
        ->call('save')
        ->assertHasNoFormErrors();

    EnforceAuthSessionCapJob::dispatchSync();

    // ⛔ WITHOUT THIS CASE, A FIELD WIRED TO THE WRONG SETTINGS KEY — OR A JOB
    // THAT HARD-CODES 180 — PASSES EVERY OTHER LINE IN THIS FILE.
    expect(AuthSession::query()->where('user_id', $user->getKey())->count())->toBe(60);
});

it('follows a retention shortened from the screen, in both directions', function (): void {
    actAsRetentionAdmin();

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->getKey()]);
    DB::table('devices')->where('id', $device->getKey())
        ->update(['created_at' => now()->subDays(100)]);

    $older = AuthSession::factory()->ended()->create([
        'user_id' => $user->getKey(), 'device_id' => $device->getKey(),
    ]);
    $newer = AuthSession::factory()->ended()->create([
        'user_id' => $user->getKey(), 'device_id' => $device->getKey(),
    ]);

    DB::table('auth_sessions')->where('id', $older->getKey())
        ->update(['created_at' => now()->subDays(61), 'ended_at' => now()->subDays(60)]);
    DB::table('auth_sessions')->where('id', $newer->getKey())
        ->update(['created_at' => now()->subDays(21), 'ended_at' => now()->subDays(20)]);

    Livewire::test(ManagePlatformSettings::class)
        ->set('data.auth_session_retain_days', 30)
        ->call('save')
        ->assertHasNoFormErrors();

    RunRetentionSweepJob::dispatchSync();

    // ⚠️ THE SECOND HALF IS THE CONTROL. Asserting only that the 60-day row was
    // swept is green against an arm that ignores `retain_days` and anonymises
    // everything it can reach.
    expect($older->fresh()->ip_hash)->toBeNull()
        ->and($newer->fresh()->ip_hash)->not->toBeNull();

    // And the number the privacy screen reads moved with it — one stored copy.
    expect((int) DataCategory::query()->where('key', 'auth_session')->value('retain_days'))->toBe(30)
        ->and((int) DataCategory::query()->where('key', 'device')->value('retain_days'))->toBe(30);
});

it('refuses a retention below the floor the grants force', function (): void {
    actAsRetentionAdmin();

    // The form's own bound, which is the door an operator actually walks through.
    Livewire::test(ManagePlatformSettings::class)
        ->set('data.auth_session_retain_days', 3)
        ->call('save')
        ->assertHasFormErrors(['auth_session_retain_days']);

    /*
    | ⛔ AND THE ACTION REFUSES IT TOO, WHICH IS THE ONLY THING THAT SEPARATES
    | ROUTING THE FIELD THROUGH `SaveDataCategory` FROM A RAW `update()`. The
    | seeders run inside `Model::unguarded()` and the panel writes with no
    | FormRequest at all, so a rule that lives only in the form has two known
    | bypasses.
    */
    $category = DataCategory::query()->where('key', 'auth_session')->firstOrFail();
    $before = (int) $category->retain_days;

    expect(fn () => app(SaveDataCategory::class)->handle(['retain_days' => 3], $category))
        ->toThrow(DomainException::class);

    expect((int) $category->fresh()->retain_days)->toBe($before);
});

it('changes no other category s retention', function (): void {
    actAsRetentionAdmin();

    $before = DataCategory::query()->pluck('retain_days', 'key')->all();

    Livewire::test(ManagePlatformSettings::class)
        ->set('data.auth_session_retain_days', 45)
        ->call('save')
        ->assertHasNoFormErrors();

    $after = DataCategory::query()->pluck('retain_days', 'key')->all();

    // FR-011 — exactly two rows moved, and they are the two this spec owns.
    $moved = array_keys(array_diff_assoc($after, $before));

    sort($moved);

    expect($moved)->toBe(['auth_session', 'device']);
});
