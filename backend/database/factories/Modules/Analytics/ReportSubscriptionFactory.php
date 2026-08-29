<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Analytics;

use App\Models\User;
use App\Modules\Analytics\Models\ReportSubscription;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Analytics\Support\ReportCadence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportSubscription> */
class ReportSubscriptionFactory extends Factory
{
    protected $model = ReportSubscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'metric_keys' => [MetricKey::StudentsActive->value, MetricKey::CollectionRate->value],
            'cadence' => ReportCadence::Weekly,
            'last_sent_on' => null,
            'is_active' => true,
        ];
    }
}
