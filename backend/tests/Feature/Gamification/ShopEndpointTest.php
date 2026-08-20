<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Modules\Identity\Support\PlatformRole;
use Laravel\Sanctum\Sanctum;

/**
 * The shop over HTTP.
 *
 * ⚠️ SEPARATE FROM ShopTest, WHICH EXERCISES THE ACTIONS. Everything this file
 * covers lives between the router and the Action: the refusal a teacher sees when
 * a form is wrong, and the fact that `WorkspaceScope` guards none of the student's
 * routes.
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->other, $this->otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Another Academy']);

    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->createEnrollment($this->workspace, $course, $this->student);
});

/*
 * ⚠️ A 422 UNDER THE FIELD, NEVER A 500 WITH A RAW MESSAGE.
 *
 * `SaveReward` enforces the mandatory cap because the seeder and the panel reach
 * it with no form behind them, and the FormRequest cannot: whether the cap is
 * required depends on the type. So the rule fires exactly where a careless
 * teacher hits it, and the repository's rule is that no raw error reaches a
 * screen.
 */
it('answers 422 under monthly_cap for a money-valued reward with no cap', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/manage/gamification/rewards', [
        'title' => 'خصم على حصة',
        'price_coins' => 100,
        'stock' => 5,
        'reward_type' => RewardType::Discount->value,
        'monthly_cap' => null,
    ])->assertStatus(422)->assertJsonValidationErrors('monthly_cap');

    expect(Reward::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('answers 422 for a cap above the platform ceiling', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/manage/gamification/rewards', [
        'title' => 'خصم على حصة',
        'price_coins' => 100,
        'stock' => 5,
        'reward_type' => RewardType::Discount->value,
        'monthly_cap' => 100_000,
    ])->assertStatus(422)->assertJsonValidationErrors('monthly_cap');
});

it('creates a reward the teacher may define', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/manage/gamification/rewards', [
        'title' => 'نسخة مطبوعة',
        'price_coins' => 80,
        'stock' => 3,
        'reward_type' => RewardType::Printed->value,
        'monthly_cap' => 5,
    ])->assertCreated()->assertJsonPath('title', 'نسخة مطبوعة');
});

/*
 * ⚠️ THE STUDENT'S ROUTES ARE GUARDED BY EXPLICIT FILTERS, NOT BY THE TRAIT.
 *
 * A student is a member of no workspace, so `WorkspaceContext::id()` is null and
 * `WorkspaceScope::apply()` adds no condition at all. These two cases are what
 * catch a regression to implicit binding or to a missing `where user_id`.
 */
it('refuses the shop of a teacher the student does not study with', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->getJson('/api/v1/gamification/shop?workspace='.$this->other->uuid)->assertForbidden();
    $this->getJson('/api/v1/gamification/shop?workspace='.$this->workspace->uuid)->assertOk();
});

it('shows a student only their own redemption requests', function (): void {
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $reward = Reward::query()->create([
        'workspace_id' => $this->other->getKey(),
        'title' => 'مكافأة غريبة',
        'price_coins' => 10,
        'stock' => 5,
        'type' => RewardType::StreakShield,
        'monthly_cap' => null,
        'is_active' => true,
    ]);

    app(ProgressWriter::class)->coinBalanceFor((int) $stranger->getKey(), (int) $this->other->getKey());

    Redemption::query()->create([
        'user_id' => $stranger->getKey(),
        'workspace_id' => $this->other->getKey(),
        'reward_id' => $reward->getKey(),
        'coins_spent' => 10,
        'claimed_month_key' => now()->format('Y-m'),
    ]);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $body = $this->getJson('/api/v1/gamification/redemptions')->assertOk()->json();

    expect($body['data'])->toBe([]);
});

it('refuses to redeem another teacher’s reward', function (): void {
    $reward = Reward::query()->create([
        'workspace_id' => $this->other->getKey(),
        'title' => 'مكافأة غريبة',
        'price_coins' => 10,
        'stock' => 5,
        'type' => RewardType::StreakShield,
        'monthly_cap' => null,
        'is_active' => true,
    ]);

    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->postJson("/api/v1/gamification/rewards/{$reward->uuid}/redeem")->assertForbidden();

    expect(Redemption::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a teacher deciding another workspace’s request', function (): void {
    $reward = Reward::query()->create([
        'workspace_id' => $this->other->getKey(),
        'title' => 'مكافأة غريبة',
        'price_coins' => 10,
        'stock' => 5,
        'type' => RewardType::StreakShield,
        'monthly_cap' => null,
        'is_active' => true,
    ]);

    $redemption = Redemption::query()->create([
        'user_id' => $this->student->getKey(),
        'workspace_id' => $this->other->getKey(),
        'reward_id' => $reward->getKey(),
        'coins_spent' => 10,
        'claimed_month_key' => now()->format('Y-m'),
    ]);

    Sanctum::actingAs($this->owner);

    /*
    | ⚠️ 404, NOT 403 — AND THAT IS THE ASYMMETRY THIS PHASE TURNS ON.
    |
    | The reader here is a workspace MEMBER, so `WorkspaceContext` resolves,
    | `WorkspaceScope` applies, and route-model binding never finds the other
    | workspace's row at all. Binding is safe on the teacher's routes for exactly
    | this reason — and unsafe on the student's, where the same scope adds no
    | condition because a student belongs to no workspace. It is also the better
    | answer: it does not confirm that the uuid exists.
    |
    | RedemptionPolicy::decide() is the second guard behind it, for the case where
    | a row does resolve.
    */
    $this->postJson("/api/v1/manage/gamification/redemptions/{$redemption->uuid}/fulfill")
        ->assertNotFound();

    expect($redemption->refresh()->decided_at)->toBeNull();
});
