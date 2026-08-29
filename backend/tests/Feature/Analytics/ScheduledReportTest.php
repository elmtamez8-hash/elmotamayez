<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Analytics\Jobs\RollUpPlatformMetricsJob;
use App\Modules\Analytics\Jobs\SendScheduledReportsJob;
use App\Modules\Analytics\Models\ReportSubscription;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Analytics\Support\ReportCadence;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| FR-045 — a standing request for the numbers, delivered on its own cadence.
*/

function scheduledReportOfficer(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

it('sends to a subscriber that has never been sent to', function (): void {
    $officer = scheduledReportOfficer();

    ReportSubscription::factory()->create([
        'user_id' => $officer->getKey(),
        'metric_keys' => [MetricKey::StudentsActive->value],
        'cadence' => ReportCadence::Weekly,
        'last_sent_on' => null,
    ]);

    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );

    app(SendScheduledReportsJob::class)->handle(
        app(ReadPlatformAnalytics::class),
        app(DispatchNotification::class),
        app(GamificationCalendar::class),
    );

    $notification = Notification::query()
        ->where('recipient_user_id', $officer->getKey())
        ->where('type', NotificationType::ScheduledReport->value)
        ->first();

    // ⚠️ ASSERTED ON THE ROW, NOT ON A DISPATCH BEING CALLED. A notification with
    // no template is DROPPED in silence, so a test that stopped at the Action
    // would be green over a delivery that never happened — which is why the
    // template ships with a backfill migration.
    expect($notification)->not->toBeNull()
        ->and(ReportSubscription::query()->sole()->last_sent_on?->toDateString())
        ->toBe(app(GamificationCalendar::class)->dayKey());
});

it('skips a subscriber whose cadence has not come round yet', function (): void {
    $officer = scheduledReportOfficer();

    ReportSubscription::factory()->create([
        'user_id' => $officer->getKey(),
        'cadence' => ReportCadence::Weekly,
        'last_sent_on' => CarbonImmutable::now()->subDays(2)->toDateString(),
    ]);

    app(SendScheduledReportsJob::class)->handle(
        app(ReadPlatformAnalytics::class),
        app(DispatchNotification::class),
        app(GamificationCalendar::class),
    );

    expect(Notification::query()->where('type', NotificationType::ScheduledReport->value)->count())->toBe(0);
});

it('skips an inactive subscription entirely', function (): void {
    ReportSubscription::factory()->create([
        'user_id' => scheduledReportOfficer()->getKey(),
        'is_active' => false,
    ]);

    app(SendScheduledReportsJob::class)->handle(
        app(ReadPlatformAnalytics::class),
        app(DispatchNotification::class),
        app(GamificationCalendar::class),
    );

    expect(Notification::query()->where('type', NotificationType::ScheduledReport->value)->count())->toBe(0);
});

it('stamps the send date BEFORE dispatching, so a lost report is not a nightly one', function (): void {
    $officer = scheduledReportOfficer();

    ReportSubscription::factory()->create([
        'user_id' => $officer->getKey(),
        'cadence' => ReportCadence::Weekly,
        'last_sent_on' => null,
    ]);

    app(SendScheduledReportsJob::class)->handle(
        app(ReadPlatformAnalytics::class),
        app(DispatchNotification::class),
        app(GamificationCalendar::class),
    );

    // A second pass on the same day must send nothing: the stamp is what stops
    // the sweep re-sending every time it runs.
    app(SendScheduledReportsJob::class)->handle(
        app(ReadPlatformAnalytics::class),
        app(DispatchNotification::class),
        app(GamificationCalendar::class),
    );

    expect(Notification::query()->where('type', NotificationType::ScheduledReport->value)->count())->toBe(1);
});

it('saves and reads back a subscription over the API', function (): void {
    Sanctum::actingAs(scheduledReportOfficer());

    $this->putJson('/api/v1/reports/subscriptions', [
        'metric_keys' => [MetricKey::StudentsActive->value, MetricKey::CollectionRate->value],
        'cadence' => 'monthly',
    ])->assertOk();

    $this->getJson('/api/v1/reports/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.cadence', 'monthly')
        ->assertJsonPath('data.is_active', true);
});

it('refuses a subscription to nothing', function (): void {
    Sanctum::actingAs(scheduledReportOfficer());

    // A subscription with no metrics is a nightly notification whose body says
    // «لا مؤشّرات مختارة» — a reminder that somebody forgot to choose, for ever.
    $this->putJson('/api/v1/reports/subscriptions', ['metric_keys' => [], 'cadence' => 'weekly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metric_keys']);
});

it('refuses a metric key that is not one of ours', function (): void {
    Sanctum::actingAs(scheduledReportOfficer());

    $this->putJson('/api/v1/reports/subscriptions', [
        'metric_keys' => ['students.active', 'salaries.everyone'],
        'cadence' => 'weekly',
    ])->assertStatus(422);
});
