<?php

declare(strict_types=1);

namespace App\Modules\Settlement;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Settlement\Events\SettlementPeriodClosed;
use App\Modules\Settlement\Events\SettlementRateApproved;
use App\Modules\Settlement\Events\TeacherPayoutIssued;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Listeners\AccrueUnitsOnDelivery;
use App\Modules\Settlement\Listeners\NotifyPayoutIssued;
use App\Modules\Settlement\Listeners\NotifyPeriodClosed;
use App\Modules\Settlement\Listeners\NotifyRateDecision;
use App\Modules\Settlement\Listeners\RecordUnitInLedger;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Policies\RateChangeRequestPolicy;
use App\Modules\Settlement\Policies\SettlementPeriodPolicy;
use App\Modules\Settlement\Policies\TeachingUnitPolicy;
use App\Modules\Settlement\Support\EloquentApprovedRateDirectory;
use App\Modules\Settlement\Support\EloquentSettlementClearance;
use App\Modules\Settlement\Support\SettlementPersonalData;
use App\Shared\Contracts\ApprovedRateDirectory;
use App\Shared\Contracts\SettlementClearance;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * The teacher's side of the money, and nothing else.
 *
 * This module is separate from Payments on purpose. The whole value of spec 014
 * is that what a student pays and what a teacher is owed are two contexts with
 * no join between them: putting this ledger inside Payments would turn FR-030
 * and FR-031 into an agreement between programmers who share a folder, and the
 * first query that joins the two tables would get written because it was within
 * reach.
 *
 * The bridge is one event, `SessionDelivered` from LiveSessions. Never a foreign
 * key, never a query across the boundary — ContextIsolationTest fails the build
 * on either.
 */
class SettlementServiceProvider extends Module
{
    protected string $name = 'Settlement';

    public function register(): void
    {
        parent::register();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([SettlementPersonalData::class], 'compliance.personal_data');

        // Settlement owns the approved rate; Payments asks through the interface
        // and never learns that `settlement_rates` exists. Same binding shape as
        // Identity's GuardianDirectory.
        //
        // bind(), not singleton(): the implementation memoises per request, and a
        // singleton's memo would outlive a Horizon job and keep quoting a rate
        // that was superseded while the worker was alive.
        $this->app->bind(ApprovedRateDirectory::class, EloquentApprovedRateDirectory::class);

        // Settlement owns the money; Compliance asks whether a departing teacher is
        // square and never learns that `ledger_entries` exists (013 · FR-032). The
        // same shape, and the same reason: `ContextIsolationTest` fails the build
        // on a query that joins these schemas from outside.
        $this->app->bind(SettlementClearance::class, EloquentSettlementClearance::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TeachingUnit::class, TeachingUnitPolicy::class);
        Gate::policy(RateChangeRequest::class, RateChangeRequestPolicy::class);
        Gate::policy(SettlementPeriod::class, SettlementPeriodPolicy::class);

        // The one bridge from the teaching side. Deliberately NOT
        // AttendanceConfirmed — see the listener for why the choice is forced by
        // the 005 code rather than preferred.
        Event::listen(SessionDelivered::class, AccrueUnitsOnDelivery::class);

        // One writer for the ledger, whatever produced the unit — delivery, a
        // late release, or a correction. Three call sites writing their own
        // entries is three chances for the balance to stop being the sum of its
        // rows.
        Event::listen(TeachingUnitAccrued::class, RecordUnitInLedger::class);

        // The teacher hears that their rate moved AND from when. A new number
        // with no date reads as applying to last week's hours, and finding out
        // otherwise on the statement is the argument this context exists to
        // prevent.
        Event::listen(SettlementRateApproved::class, NotifyRateDecision::class);

        // FR-029. A total that stops moving without anyone saying so, and money
        // that arrives without a reference, are both discovered rather than told
        // — and by then the question is an argument instead of a query.
        Event::listen(SettlementPeriodClosed::class, NotifyPeriodClosed::class);
        Event::listen(TeacherPayoutIssued::class, NotifyPayoutIssued::class);
    }
}
