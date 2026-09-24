<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Identity\Support\ReferralStatus;

/*
| The «كود الإحالة» field on `/signup/student`, driven through the real route.
|
| ⚠️ THE ACTION TESTS DO NOT COVER THIS. `SelfReferralTest` calls
| `AttachReferral` directly, so it proves the attach and never the door: until
| this file no test posted a `referral_code` to `/auth/register/student`, and no
| screen sent one either — so no referral had ever been captured.
|
| ⚠️ AN UNKNOWN CODE IS A 422 HERE, NOT A SILENT DROP. The field is visible and
| prefilled from a `?ref=` link, so dropping a bad code leaves the friend
| uncredited while the newcomer believes they were referred. The field is
| optional, so the person stopped can clear it and carry on.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function referralSignupPayload(array $overrides = []): array
{
    return [
        'first_name' => 'ليلى',
        'last_name' => 'الهاجري',
        'email' => 'layla@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455512399',
        'country' => 'QA',
        'school_year_slug' => 'year-10',
        'region_slug' => 'doha',
        'date_of_birth' => '1998-04-12',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->asGuest();

    $this->inviter = User::factory()->create();
    ReferralCode::factory()->create(['user_id' => $this->inviter->getKey(), 'code' => 'FRIEND23']);
});

it('attaches a pending referral when a valid code is sent through the route', function (): void {
    $this->postJson('/api/v1/auth/register/student', referralSignupPayload(['referral_code' => 'FRIEND23']))
        ->assertCreated();

    $newcomer = User::query()->where('email', 'layla@example.com')->sole();
    $referral = Referral::query()->where('referred_user_id', $newcomer->getKey())->sole();

    expect((int) $referral->referrer_user_id)->toBe((int) $this->inviter->getKey())
        ->and($referral->status)->toBe(ReferralStatus::Pending);
});

it('matches a code however it was typed', function (): void {
    // Normalised BEFORE the `exists` rule: MySQL's collation would match a
    // lower-case code where SQLite does not, and both must answer alike.
    $this->postJson('/api/v1/auth/register/student', referralSignupPayload(['referral_code' => '  friend23 ']))
        ->assertCreated();

    expect(Referral::query()->count())->toBe(1);
});

it('refuses an unknown code under its own field and creates nobody', function (): void {
    $usersBefore = User::query()->count();

    $this->postJson('/api/v1/auth/register/student', referralSignupPayload(['referral_code' => 'NOSUCH99']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['referral_code'])
        ->assertJsonMissingValidationErrors(['email', 'first_name'])
        ->assertJsonPath('errors.referral_code.0', 'لم نجد هذا الكود. تأكّد منه، أو امسح الخانة وأكمل التسجيل بدونه.');

    expect(User::query()->count())->toBe($usersBefore)
        ->and(User::query()->where('email', 'layla@example.com')->exists())->toBeFalse()
        ->and(Referral::query()->count())->toBe(0);
});

it('registers without a referral when the field is empty or absent', function (): void {
    $this->postJson('/api/v1/auth/register/student', referralSignupPayload(['referral_code' => '   ']))
        ->assertCreated();

    $this->postJson('/api/v1/auth/register/student', referralSignupPayload([
        'email' => 'second@example.com',
        'phone' => '+97455512398',
    ]))->assertCreated();

    expect(Referral::query()->count())->toBe(0);
});
