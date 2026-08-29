<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Models\User;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 *
 * ⚠️ THE KEYS ARE ORDERED, NOT ALPHABETISED. Laravel expands a definition array
 * in sequence and hands each closure the attributes resolved so far, so
 * `plan_id` has to stand above the two closures that read it. There is no
 * `OrderFactory` in this tree — orders are written explicitly everywhere — so
 * the order is built here from the attributes the caller may have overridden,
 * which is what keeps `order_id` unique per subscription and the workspace
 * agreeing with the plan's.
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $starts = CarbonImmutable::today();
        $ends = $starts->addDays(30);

        return [
            'plan_id' => Plan::factory(),
            'student_user_id' => User::factory(),

            'workspace_id' => fn (array $attributes): int => (int) Plan::query()
                ->withoutWorkspaceScope()
                ->whereKey($attributes['plan_id'])
                ->value('workspace_id'),

            'price_minor' => 30_000,
            'currency' => 'QAR',

            'order_id' => fn (array $attributes): int => Order::create([
                'workspace_id' => $attributes['workspace_id'],
                'user_id' => $attributes['student_user_id'],
                'kind' => OrderKind::Subscription,
                'amount_minor' => $attributes['price_minor'],
                'currency' => $attributes['currency'],
                'provider' => 'manual',
                'status' => 'approved',
            ])->getKey(),

            'starts_on' => $starts,
            'ends_on' => $ends,
            // Equal to `ends_on` unless a freeze extended it. Never computed
            // here: `EffectiveSubscriptionEnd` owns that arithmetic, and a
            // factory that guessed it would be a second answer to the question
            // the whole column exists to settle.
            'effective_ends_on' => $ends,
            'status' => SubscriptionStatus::Active,
        ];
    }

    public function expired(): self
    {
        return $this->state(function (): array {
            $ends = CarbonImmutable::today()->subDay();

            return [
                'starts_on' => $ends->subDays(30),
                'ends_on' => $ends,
                'effective_ends_on' => $ends,
                'status' => SubscriptionStatus::Expired,
            ];
        });
    }

    /** Live, and ending in `$days` days — the sweep's and the notice's fixture. */
    public function endingIn(int $days): self
    {
        return $this->state(function () use ($days): array {
            $ends = CarbonImmutable::today()->addDays($days);

            return [
                'starts_on' => $ends->subDays(30),
                'ends_on' => $ends,
                'effective_ends_on' => $ends,
                'status' => SubscriptionStatus::Active,
            ];
        });
    }
}
