<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\DecideRedemption;
use App\Modules\Gamification\Actions\RedeemReward;
use App\Modules\Gamification\Actions\SaveReward;
use App\Modules\Gamification\Data\RewardData;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Exceptions\RedemptionRefused;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Modules\Identity\Support\PlatformRole;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The reward shop (FR-029 … FR-037 · SC-004 · SC-012 · SC-013).
 *
 * The source document is blunt about why this exists: points with nowhere to
 * spend them lose their meaning within two weeks.
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    $this->fund = function (int $coins): void {
        app(ProgressWriter::class)->coinBalanceFor((int) $this->student->getKey(), (int) $this->workspace->getKey());

        CoinBalance::query()->withoutWorkspaceScope()
            ->where('user_id', $this->student->getKey())
            ->where('workspace_id', $this->workspace->getKey())
            ->update(['coins' => $coins]);
    };
});

function shopReward(int $workspaceId, array $attributes = []): Reward
{
    return Reward::query()->create([
        'workspace_id' => $workspaceId,
        'title' => 'مكافأة',
        'price_coins' => 50,
        'stock' => 5,
        'type' => RewardType::Printed,
        'monthly_cap' => 10,
        'is_active' => true,
        ...$attributes,
    ]);
}

function purseOf(User $student, int $workspaceId): int
{
    return (int) CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->where('workspace_id', $workspaceId)
        ->value('coins');
}

it('deducts the coins and creates a pending request', function (): void {
    ($this->fund)(120);

    $reward = shopReward((int) $this->workspace->getKey());

    $redemption = app(RedeemReward::class)->handle($this->student, $reward->uuid);

    expect($redemption->status)->toBe(RedemptionStatus::Pending)
        ->and($redemption->coins_spent)->toBe(50)
        ->and(purseOf($this->student, (int) $this->workspace->getKey()))->toBe(70)
        ->and($reward->refresh()->stock)->toBe(4);
});

it('refuses when the coins are not enough, and takes nothing', function (): void {
    ($this->fund)(10);

    $reward = shopReward((int) $this->workspace->getKey());

    expect(fn () => app(RedeemReward::class)->handle($this->student, $reward->uuid))
        ->toThrow(RedemptionRefused::class);

    // ⚠️ AND THE CLAIM ROLLED BACK WITH IT. The reward was claimed before the
    // coins were checked, so a partial failure that left the stock decremented
    // would lose a unit to a purchase that never happened.
    expect($reward->refresh()->stock)->toBe(5)
        ->and(purseOf($this->student, (int) $this->workspace->getKey()))->toBe(10)
        ->and(Redemption::query()->count())->toBe(0);
});

/*
 * ⚠️ AN UNCAPPED REWARD MUST BE REDEEMABLE MORE THAN ONCE A MONTH.
 *
 * The claim's predicate includes `monthly_cap IS NULL`, and without that clause
 * every redemption after the first in a month is refused on an uncapped reward —
 * INCLUDING THE STREAK SHIELD, which is the one thing students buy repeatedly.
 * The bug would look like "the shop stopped working" a few days into every month.
 */
it('lets an uncapped reward be redeemed repeatedly in one month', function (): void {
    ($this->fund)(500);

    $reward = shopReward((int) $this->workspace->getKey(), [
        'monthly_cap' => null,
        'type' => RewardType::StreakShield,
    ]);

    foreach (range(1, 3) as $ignored) {
        app(RedeemReward::class)->handle($this->student, $reward->uuid);
    }

    expect(Redemption::query()->count())->toBe(3)
        // And the shield is credited at redemption: nothing for the teacher to do.
        ->and(StudentProgress::query()->where('user_id', $this->student->getKey())->sole()->shield_count)->toBe(3);
});

it('refuses past the monthly cap and says why', function (): void {
    ($this->fund)(500);

    $reward = shopReward((int) $this->workspace->getKey(), ['monthly_cap' => 2, 'stock' => 50]);

    app(RedeemReward::class)->handle($this->student, $reward->uuid);
    app(RedeemReward::class)->handle($this->student, $reward->uuid);

    try {
        app(RedeemReward::class)->handle($this->student, $reward->uuid);
        $this->fail('a third redemption should have been refused');
    } catch (RedemptionRefused $refusal) {
        expect($refusal->reason)->toBe('monthly_cap_reached');
    }
});

/*
 * SC-004 — two claims on the last unit.
 *
 * The window is opened inside the claim, the way spec 017 reproduces its own
 * race: no threads, no sleeps, and it fails against a read-then-write guard.
 */
it('lets only one of two overlapping claims take the last unit', function (): void {
    ($this->fund)(500);

    $other = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->createEnrollment($this->workspace, $this->course, $other);
    app(ProgressWriter::class)->coinBalanceFor((int) $other->getKey(), (int) $this->workspace->getKey());
    CoinBalance::query()->withoutWorkspaceScope()->where('user_id', $other->getKey())->update(['coins' => 500]);

    $reward = shopReward((int) $this->workspace->getKey(), ['stock' => 1, 'monthly_cap' => null]);

    $fired = false;

    DB::listen(function ($query) use (&$fired, $other, $reward): void {
        if ($fired || ! str_contains($query->sql, 'rewards') || stripos(trim($query->sql), 'update') !== 0) {
            return;
        }

        $fired = true;

        // The other student takes the last unit inside the window.
        try {
            app(RedeemReward::class)->handle($other, $reward->uuid);
        } catch (RedemptionRefused) {
            // Whichever order they land in, only one can win.
        }
    });

    try {
        app(RedeemReward::class)->handle($this->student, $reward->uuid);
    } catch (RedemptionRefused) {
        // Expected for the loser.
    }

    expect($reward->refresh()->stock)->toBe(0)
        ->and($reward->stock)->toBeGreaterThanOrEqual(0)
        ->and(Redemption::query()->count())->toBe(1);
});

it('returns the coins in full when the request is rejected', function (): void {
    ($this->fund)(120);

    $reward = shopReward((int) $this->workspace->getKey(), ['monthly_cap' => 2]);

    $first = app(RedeemReward::class)->handle($this->student, $reward->uuid);
    $second = app(RedeemReward::class)->handle($this->student, $reward->uuid);

    app(DecideRedemption::class)->handle($first, $this->owner, RedemptionStatus::Rejected);
    app(DecideRedemption::class)->handle($second, $this->owner, RedemptionStatus::Rejected);

    expect(purseOf($this->student, (int) $this->workspace->getKey()))->toBe(120)
        ->and($reward->refresh()->stock)->toBe(5)
        // ⚠️ AND THE REWARD IS REDEEMABLE AGAIN. Without releasing the monthly
        // counter the cap fills with rejected requests and nothing looks broken —
        // no error, just a reward nobody can take for the rest of the month.
        ->and($reward->month_redeemed)->toBe(0);

    expect(fn () => app(RedeemReward::class)->handle($this->student, $reward->uuid))->not->toThrow(RedemptionRefused::class);
});

/*
 * ⚠️ TWO CLICKS ON REJECT MUST NOT REFUND TWICE.
 *
 * This is the direction FR-034 does not guard: that requirement is written about
 * balances going negative, and a double refund creates coins out of nothing. The
 * atomic status claim is what stops it.
 */
it('refunds once however many times it is rejected', function (): void {
    ($this->fund)(120);

    $reward = shopReward((int) $this->workspace->getKey());
    $redemption = app(RedeemReward::class)->handle($this->student, $reward->uuid);

    expect(app(DecideRedemption::class)->handle($redemption, $this->owner, RedemptionStatus::Rejected))->toBeTrue()
        ->and(app(DecideRedemption::class)->handle($redemption, $this->owner, RedemptionStatus::Rejected))->toBeFalse()
        ->and(purseOf($this->student, (int) $this->workspace->getKey()))->toBe(120)
        ->and($reward->refresh()->stock)->toBe(5);
});

/*
 * ⚠️ A REJECTION AFTER THE MONTH ROLLED OVER STILL RETURNS THE STOCK.
 *
 * The release is two statements for this reason. Folded into one conditioned on
 * the current month, this matches nothing and loses a unit of stock PERMANENTLY
 * for a reward nobody ever received.
 */
it('returns the stock even when the month has rolled over', function (): void {
    ($this->fund)(120);

    $reward = shopReward((int) $this->workspace->getKey(), ['monthly_cap' => 3]);
    $redemption = app(RedeemReward::class)->handle($this->student, $reward->uuid);

    // The month turns: the reward's counter has moved on to a new key.
    DB::table('rewards')->where('id', $reward->getKey())
        ->update(['month_key' => '1999-01', 'month_redeemed' => 1]);

    app(DecideRedemption::class)->handle($redemption, $this->owner, RedemptionStatus::Rejected);

    expect($reward->refresh()->stock)->toBe(5)
        ->and(purseOf($this->student, (int) $this->workspace->getKey()))->toBe(120)
        // The new month's counter is untouched: giving back a slot from the wrong
        // month would be as wrong as losing one.
        ->and($reward->month_redeemed)->toBe(1);
});

it('refuses a money-valued reward with no monthly cap', function (): void {
    $data = new RewardData(
        title: 'خصم على حصة',
        priceCoins: 100,
        stock: 5,
        type: RewardType::Discount,
        monthlyCap: null,
        isActive: true,
    );

    expect(fn () => app(SaveReward::class)->handle($data, (int) $this->workspace->getKey()))
        ->toThrow(DomainException::class);
});

it('refuses a monthly cap above the platform ceiling', function (): void {
    $data = new RewardData(
        title: 'خصم على حصة',
        priceCoins: 100,
        stock: 5,
        type: RewardType::Discount,
        monthlyCap: 100_000,
        isActive: true,
    );

    expect(fn () => app(SaveReward::class)->handle($data, (int) $this->workspace->getKey()))
        ->toThrow(DomainException::class);
});

/*
 * ⚠️ THE SCOPE IS INERT FOR A STUDENT, so this is the guard being tested and not
 * BelongsToWorkspace. A student belongs to no workspace at all.
 */
it('refuses a reward belonging to a teacher the student does not study with', function (): void {
    ($this->fund)(500);

    [$other] = $this->createWorkspaceWithOwner(['name' => 'Another Academy']);
    $reward = shopReward((int) $other->getKey());

    expect(fn () => app(RedeemReward::class)->handle($this->student, $reward->uuid))
        ->toThrow(HttpException::class);

    expect(Redemption::query()->count())->toBe(0);
});
