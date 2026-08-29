<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Models\ReportSubscription;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Analytics' half of the data-rights contract (spec 013 · spec 011 · US6).
 *
 * ⚠️ THIS FILE EXISTS BECAUSE `report_subscriptions` NAMES A PERSON. The module
 * had no `Schema::create` of its own until this phase and was exempted from
 * `PersonalDataContractCoverageTest` in those words; the exemption comes off in
 * the same change as the table, because an exemption whose stated reason has
 * expired is a guard that passes over a lie.
 *
 * ⚠️ AND THE METRIC ROWS ARE NOT HERE, DELIBERATELY. `platform_metrics_daily`
 * holds counts and nothing else — no user column, no way back to a person — so
 * it is not personal data and a category for it would be a row the sweep walks
 * every night to find nothing.
 */
class AnalyticsPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'analytics';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['report_subscription'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY — the contract's second obligation. And it
     * yields the category even when there is nothing in it: an empty section is
     * an answer, a missing one is silence.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        yield from ExportWalk::keyed(
            'report_subscription',
            ReportSubscription::query()->where('user_id', $subject->user->getKey()),
            fn (ReportSubscription $subscription): array => [
                'uuid' => $subscription->uuid,
                'metric_keys' => $subscription->metric_keys,
                'cadence' => $subscription->cadence->value,
                'is_active' => $subscription->is_active,
                'last_sent_on' => $subscription->last_sent_on?->toDateString(),
            ],
        );
    }

    /** ⚠️ THE MODE IS RECEIVED, NEVER INVENTED. */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        return ReportSubscription::query()
            ->where('user_id', $subject->user->getKey())
            ->limit($limit)
            ->delete();
    }

    /**
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($category !== 'report_subscription' || $mode !== ExpiryBehaviour::Delete) {
            return 0;
        }

        $query = ReportSubscription::query()->where('created_at', '<', $before->toDateTimeString());

        /*
        | FR-030's fourth door: retention needs no request, so a hold that only
        | suspended erasure REQUESTS would let this sweep delete the rows a court
        | ordered kept. `user_id` is NOT NULL here, so a bare `whereNotIn` is
        | correct — the `orWhereNull` trap only bites on a nullable column.
        */
        if ($exemptUserIds !== []) {
            $query->whereNotIn('user_id', $exemptUserIds);
        }

        return $query->limit($limit)->delete();
    }
}
