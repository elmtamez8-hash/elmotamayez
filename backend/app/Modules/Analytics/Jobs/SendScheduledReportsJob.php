<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Analytics\Models\ReportSubscription;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The numbers, posted to whoever asked for them (spec 011 · FR-045).
 *
 * ⚠️ THERE IS NO REPORT GENERATOR HERE. The report IS the rows of
 * `platform_metrics_daily` that the dashboard reads — a second computation for
 * the email would be a second answer to the same question, and the day the two
 * disagreed nobody would know which one was wrong.
 *
 * ⚠️ `last_sent_on` IS STAMPED BEFORE THE DISPATCH, the `notified_dormant_at`
 * shape. The dispatch is queued, so a worker dying between the two orderings
 * costs either ONE lost report or a report every night for ever — and only one
 * of those is recoverable by the person receiving it.
 *
 * ⚠️ AND IT GOES THROUGH `DispatchNotification`, naming a TYPE and never a
 * channel. `ProviderAgnosticTest` fails the build over an Action that names one;
 * a job is not covered by that sweep, which is exactly why the rule is written
 * here as well.
 */
class SendScheduledReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        ReadPlatformAnalytics $analytics,
        DispatchNotification $notifications,
        GamificationCalendar $calendar,
    ): void {
        $today = CarbonImmutable::parse($calendar->dayKey());

        $report = null;

        ReportSubscription::query()
            ->where('is_active', true)
            ->with(['user'])
            ->chunkById(100, function (Collection $subscriptions) use (&$report, $analytics, $notifications, $today): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->cadence->isDue($subscription->last_sent_on, $today)) {
                        continue;
                    }

                    $recipient = $subscription->user;

                    if ($recipient === null) {
                        continue;
                    }

                    // Read once for the whole sweep: every subscriber is looking
                    // at the same platform on the same day.
                    $report ??= $analytics->handle();

                    $subscription->forceFill(['last_sent_on' => $today->toDateString()])->save();

                    $notifications->handle(new NotificationRequest(
                        recipient: $recipient,
                        type: NotificationType::ScheduledReport,
                        variables: [
                            'period' => $subscription->cadence->label(),
                            'date' => (string) ($report['date'] ?? $today->toDateString()),
                            'summary' => $this->summarise($report, $subscription->metric_keys),
                        ],
                    ));
                }
            });
    }

    /**
     * The metrics this person asked for, as one sentence.
     *
     * A key that is no longer a metric is skipped rather than printed raw: the
     * subscription is a stored list and the enum is what the platform actually
     * computes, so the two can drift by exactly one release.
     *
     * @param  array<string, mixed>  $report
     * @param  list<string>  $keys
     */
    private function summarise(array $report, array $keys): string
    {
        /** @var list<array<string, mixed>> $metrics */
        $metrics = $report['metrics'] ?? [];

        $parts = [];

        foreach ($metrics as $metric) {
            $key = (string) ($metric['key'] ?? '');

            if (! in_array($key, $keys, true) || MetricKey::tryFrom($key) === null) {
                continue;
            }

            $value = (bool) ($metric['is_ratio'] ?? false)
                ? number_format(((float) $metric['value']) * 100, 1).'٪'
                : number_format((float) ($metric['numerator'] ?? 0));

            $parts[] = $metric['label'].': '.$value;
        }

        return $parts === [] ? 'لا مؤشّرات مختارة.' : implode(' · ', $parts);
    }
}
