<?php

declare(strict_types=1);

namespace App\Modules\Settlement;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use App\Modules\Settlement\Listeners\AccrueUnitsOnDelivery;
use App\Modules\Settlement\Listeners\RecordUnitInLedger;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Policies\RateChangeRequestPolicy;
use App\Modules\Settlement\Policies\SettlementPeriodPolicy;
use App\Modules\Settlement\Policies\TeachingUnitPolicy;
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
    }
}
