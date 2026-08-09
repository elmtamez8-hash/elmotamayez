<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\StudentCreditAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditBalance>
 */
class CreditBalanceFactory extends Factory
{
    protected $model = CreditBalance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_credit_account_id' => StudentCreditAccount::factory(),
            // Denormalised from the account and NOT NULL, so a factory that
            // omitted it would fail on the first create rather than seed a null.
            'student_user_id' => User::factory(),
            'course_id' => Course::factory(),
            'purchased_credits' => 0,
            'consumed_credits' => 0,
            'remaining_credits' => 0,
            // Zero, matching the launch policy: no ceiling without a recorded
            // consent, and none at all in PREPAID_CREDITS (FR-014).
            'credit_limit_credits' => 0,
            'notified_tier' => 0,
        ];
    }

    /**
     * A balance holding credits, with its counters consistent.
     *
     * The invariant remaining = purchased − consumed is asserted by SC-001, so a
     * factory that set only `remaining` would seed states the engine can never
     * produce and make the assertion pass against fiction.
     */
    public function withCredits(int $credits): self
    {
        return $this->state(fn (): array => [
            'purchased_credits' => $credits,
            'consumed_credits' => 0,
            'remaining_credits' => $credits,
        ]);
    }

    /** A balance in debt, as a deferring mode allows. */
    public function negative(int $credits, int $limit = 4): self
    {
        return $this->state(fn (): array => [
            'purchased_credits' => 0,
            'consumed_credits' => $credits,
            'remaining_credits' => -$credits,
            'credit_limit_credits' => $limit,
            'negative_since' => now(),
        ]);
    }
}
