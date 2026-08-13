<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\Payments\Models\PaymentReconciliationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentReconciliationRun>
 */
class PaymentReconciliationRunFactory extends Factory
{
    protected $model = PaymentReconciliationRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $to = now();

        return [
            'ran_at' => $to,
            // An hour behind, which is the lookback a first run uses. A factory
            // that made the window zero-length would let a test think the sweep
            // had covered a period it never looked at.
            'window_from' => $to->copy()->subHour(),
            'window_to' => $to,
            'checked_count' => 0,
            'corrected_count' => 0,
            'unresolved_count' => 0,
            'findings' => [],
        ];
    }
}
