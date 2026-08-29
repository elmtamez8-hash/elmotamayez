<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\IssueReferralCode;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Identity\Support\ReferralStatus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| A `GET` that writes, and it races with itself (T088).
|
| ⚠️ TWO CONCURRENT LOADS OF THE REFERRALS PAGE BOTH FIND NO ROW AND BOTH INSERT.
| The loser gets a `QueryException` rendered as a **500 on a read** — the shape a
| user reports as «the page works sometimes», and the reason `IssueReferralCode`
| is a loop rather than one `firstOrCreate`. Two different unique indexes bite
| here and they need different answers: `user_id` means the other request won and
| their row is just as good as ours, while `code` means a random collision that
| must be retried with a DIFFERENT value.
|
| The seam below is the second request winning inside exactly that window — no
| threads, no sleeps. A sequential «call it twice» test does NOT cover it: the
| second call returns at the read one step earlier and never reaches the insert.
*/
beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('returns the same code however many times it is asked', function (): void {
    $first = app(IssueReferralCode::class)->handle($this->user);
    $second = app(IssueReferralCode::class)->handle($this->user);

    expect($second->code)->toBe($first->code)
        ->and(ReferralCode::query()->where('user_id', $this->user->getKey())->count())->toBe(1);
});

it('survives another request winning between its read and its insert', function (): void {
    $fired = false;

    DB::listen(function ($query) use (&$fired): void {
        if ($fired || ! str_starts_with($query->sql, 'select * from "referral_codes"')) {
            return;
        }

        $fired = true;

        // The rival, outside this Action: it takes the one row `user_id` allows.
        ReferralCode::query()->create([
            'user_id' => $this->user->getKey(),
            'code' => 'RIVALCODE',
        ]);
    });

    $code = app(IssueReferralCode::class)->handle($this->user);

    expect($fired)->toBeTrue('the seam never fired — the test proved nothing')
        // Their row, not an exception. One person, one code.
        ->and($code->code)->toBe('RIVALCODE')
        ->and(ReferralCode::query()->where('user_id', $this->user->getKey())->count())->toBe(1);
});

it('retries with a different code when a random one collides', function (): void {
    // The second index. A `firstOrCreate` alone would retry the SAME colliding
    // value for ever, because re-reading by `user_id` still finds nothing.
    $taken = ReferralCode::factory()->create(['code' => 'TAKENCOD']);

    $fired = false;

    DB::listen(function ($query) use (&$fired, $taken): void {
        if ($fired || ! str_starts_with($query->sql, 'select * from "referral_codes"')) {
            return;
        }

        $fired = true;

        // Force the collision the generator is too unlikely to produce: the row
        // the Action is about to insert will clash on `code`, not on `user_id`.
        DB::table('referral_codes')->where('id', $taken->getKey())->update(['code' => 'CLASHING']);
    });

    $code = app(IssueReferralCode::class)->handle($this->user);

    expect($code->code)->not->toBe('CLASHING')
        ->and($code->user_id)->toBe($this->user->getKey());
});

it('mints a code the first time the page is opened', function (): void {
    /*
    | The endpoint is a `GET` that writes on purpose: every account that predates
    | this feature gets a code the moment its owner looks, rather than needing a
    | backfill over the whole users table.
    */
    Sanctum::actingAs($this->user);

    expect(ReferralCode::query()->count())->toBe(0);

    $body = $this->getJson('/api/v1/referrals/code')->assertOk()->json();

    expect($body['code'])->toBeString()
        ->and(strlen($body['code']))->toBeGreaterThan(4)
        ->and($body['completed_count'])->toBe(0);
});

it('lists only the referrals this person sent', function (): void {
    /*
    | ⚠️ `referrals` HAS NO WORKSPACE SCOPE BEHIND IT — a code belongs to a
    | person, not a classroom — and the caller is a student, for whom the context
    | is null and `WorkspaceScope` adds no condition anyway. The explicit
    | `where('referrer_user_id', …)` is the entire guard; drop it and this route
    | returns every referral on the platform. Spec 009's
    | `GET /gamification/redemptions` is the same lesson.
    */
    $stranger = User::factory()->create();

    Referral::factory()->completed()->create([
        'referrer_user_id' => $this->user->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
    ]);

    Referral::factory()->completed()->create([
        'referrer_user_id' => $stranger->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
    ]);

    Sanctum::actingAs($this->user);

    $rows = $this->getJson('/api/v1/referrals')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe(ReferralStatus::Completed->value)
        // The invited person is deliberately not named: an inviter already knows
        // who they invited, and a list of names and addresses assembled out of
        // other people's signups is a contact list nobody consented to.
        ->and($rows[0])->not->toHaveKey('referred_user')
        ->and($rows[0])->not->toHaveKey('flagged_reason');
});

it('counts only completed referrals in the headline number', function (): void {
    Referral::factory()->completed()->create([
        'referrer_user_id' => $this->user->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
    ]);

    Referral::create([
        'referrer_user_id' => $this->user->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
    ]);

    Referral::factory()->flagged()->create([
        'referrer_user_id' => $this->user->getKey(),
        'referred_user_id' => User::factory()->create()->getKey(),
    ]);

    Sanctum::actingAs($this->user);

    expect($this->getJson('/api/v1/referrals/code')->assertOk()->json('completed_count'))->toBe(1);
});
