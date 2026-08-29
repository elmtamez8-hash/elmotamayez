<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Analytics\Http\Requests\SaveReportSubscriptionRequest;
use App\Modules\Analytics\Models\ReportSubscription;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Analytics\Support\ReportCadence;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A standing request for the platform's numbers (spec 011 · FR-045 · FR-046).
 *
 * ⚠️ THE SAME PERMISSION AS THE DASHBOARD, checked on every method. A
 * subscription is a copy of the report delivered to a phone, so a door that was
 * any wider than the screen's would be the screen's rule with a delivery
 * mechanism around it. `analytics.view` — the WORKSPACE permission an assistant
 * holds — is deliberately not it.
 *
 * ⚠️ AND THE ROW IS ALWAYS THE CALLER'S OWN. There is no uuid in any of these
 * routes: a subscription belongs to the person reading it, so «whose row» is
 * never a parameter that could name somebody else's.
 */
class ReportSubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        abort_unless($user->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW), 403);

        /** @var ReportSubscription|null $subscription */
        $subscription = ReportSubscription::query()->where('user_id', $user->getKey())->first();

        return response()->json([
            'data' => [
                // No row is a real state — «you have not subscribed» — answered
                // with the defaults the form would open on rather than a 404.
                'metric_keys' => $subscription === null ? [] : $subscription->metric_keys,
                'cadence' => ($subscription === null ? ReportCadence::Weekly : $subscription->cadence)->value,
                'is_active' => $subscription !== null && $subscription->is_active,
                'last_sent_on' => $subscription?->last_sent_on?->toDateString(),
                // The catalogue the picker renders — derived from the enum, never
                // a second list in TypeScript that ages at the next metric.
                'available' => array_map(
                    fn (MetricKey $key): array => ['key' => $key->value, 'label' => $key->label()],
                    MetricKey::cases(),
                ),
            ],
        ]);
    }

    public function save(SaveReportSubscriptionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        abort_unless($user->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW), 403);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $subscription = ReportSubscription::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'metric_keys' => array_values((array) $data['metric_keys']),
                'cadence' => $data['cadence'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ],
        );

        return response()->json([
            'data' => [
                'metric_keys' => $subscription->metric_keys,
                'cadence' => $subscription->cadence->value,
                'is_active' => $subscription->is_active,
            ],
        ]);
    }

    /** The dashboard's own payload, for the screen that shows it outside `/admin`. */
    public function report(Request $request, ReadPlatformAnalytics $action): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::ANALYTICS_CROSS_TEACHER_VIEW), 403);

        return response()->json(['data' => $action->handle()]);
    }
}
