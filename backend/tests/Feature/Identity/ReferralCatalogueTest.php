<?php

declare(strict_types=1);

use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Tenancy\Support\PlatformSettings;

/*
| The row without which the whole feature is silent (T087).
|
| ⚠️ THIS FILE EXISTS BECAUSE A GREEN SUITE CANNOT SEE THE DEFECT IT GUARDS.
| `tests/Pest.php` seeds the gamification catalogue before every case, so every
| other assertion about `invite_friend` in this phase is made against a table
| production may not have — and `AwardPoints` returns in SILENCE for an unknown
| key, so a live database missing the row would complete referrals and pay
| nothing at all, with no error anywhere.
|
| That is not hypothetical: `helpful_answer` shipped exactly that way in spec 010
| and was found by walking the product by hand, not by a test. This is the fourth
| runtime catalogue in this tree to need a backfill migration beside its seeder.
|
| ⚠️ SO EACH CASE DELETES THE ROW FIRST AND RUNS THE MIGRATION'S OWN `up()`.
| Asserting the row is present proves only that `Pest.php` ran.
*/
function runTheBackfill(): void
{
    $migration = require base_path(
        'app/Modules/Identity/Database/Migrations/2026_08_29_003100_backfill_referral_catalogue_action.php',
    );

    $migration->up();
}

it('puts the invite_friend row into a database that does not have it', function (): void {
    GamificationAction::query()->where('key', 'invite_friend')->delete();

    expect(GamificationAction::query()->where('key', 'invite_friend')->exists())->toBeFalse();

    runTheBackfill();

    $action = GamificationAction::query()->where('key', 'invite_friend')->firstOrFail();

    expect($action->is_active)->toBeTrue()
        ->and((int) $action->xp)->toBeGreaterThan(0);
});

it('gives the row ZERO coins, or every completed referral is a 500', function (): void {
    /*
    | ⚠️ NOT COSMETIC. `AwardPoints` THROWS on a coin-bearing action with a null
    | workspace rather than guessing a purse — and a referral belongs to no
    | teacher, so the award listener passes none. Coins here would turn every
    | completed referral into an exception inside a queued job.
    */
    GamificationAction::query()->where('key', 'invite_friend')->delete();

    runTheBackfill();

    expect((int) GamificationAction::query()->where('key', 'invite_friend')->value('coins'))->toBe(0);
});

it('gives the row NO daily cap, or a referral completes and pays nothing', function (): void {
    // Past a daily cap `AwardPoints` returns null, which would leave the referral
    // flipped `completed` with nothing awarded — invisible, and unrepeatable
    // because the flip is one-way. The governor is the platform cap instead.
    GamificationAction::query()->where('key', 'invite_friend')->delete();

    runTheBackfill();

    expect(GamificationAction::query()->where('key', 'invite_friend')->value('daily_cap'))->toBeNull();
});

it('seeds the value from the platform setting', function (): void {
    // FR-023's «the reward value must be adjustable», at the one seam where the
    // setting is read. The catalogue is authoritative afterwards.
    GamificationAction::query()->where('key', 'invite_friend')->delete();

    PlatformSettings::set('referral.reward_points', 77);

    runTheBackfill();

    expect((int) GamificationAction::query()->where('key', 'invite_friend')->value('xp'))->toBe(77);

    PlatformSettings::flush();
});

it('never overwrites a value an operator tuned', function (): void {
    /*
    | ⚠️ `firstOrCreate`, NEVER `updateOrCreate`. Every catalogue row is editable
    | from `/admin`, so an overwrite in a deploy path resets an xp value somebody
    | chose — on every release, silently. Third instance of this shape in the
    | tree, and the reason all four catalogue seeders now have two modes.
    */
    GamificationAction::query()->where('key', 'invite_friend')->update(['xp' => 999]);

    runTheBackfill();

    expect((int) GamificationAction::query()->where('key', 'invite_friend')->value('xp'))->toBe(999);
});

it('is safe to run twice', function (): void {
    GamificationAction::query()->where('key', 'invite_friend')->delete();

    runTheBackfill();
    runTheBackfill();

    expect(GamificationAction::query()->where('key', 'invite_friend')->count())->toBe(1);
});
