<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Contracts\SettlementClearance;
use App\Shared\Data\SettlementStanding;

/**
 * What a departing teacher is owed and owes (spec 013 · FR-032 · SC-013).
 *
 * ⚠️ A CONTRACT RATHER THAN A QUERY FROM `Compliance`, because
 * `ContextIsolationTest` fails the build on anything joining the settlement and
 * billing schemas — and a `Compliance` query against `ledger_entries` is that
 * join wearing a third module's name. The implementation lives here, where the
 * tables belong; `Compliance` sees two integers and a boolean.
 *
 * ⚠️ AND EVERY QUERY DECLARES `withoutWorkspaceScope()` WITH AN EXPLICIT
 * `workspace_id`. This is asked by a PLATFORM officer about SOMEBODY ELSE'S
 * workspace, and `WorkspaceContext::id()` falls back to `users.last_workspace_id`
 * for every user including a super admin — so left scoped, the ledger query
 * returns zero rows, `isCleared()` answers true, and an offboarding completes with
 * money outstanding in either direction. SC-013 broken by a global scope, green
 * on any single-workspace fixture. The audit chain already shipped that exact
 * defect once, answering "nothing was bought" with a 200.
 */
class EloquentSettlementClearance implements SettlementClearance
{
    public function outstandingFor(User $teacher, int $workspaceId): SettlementStanding
    {
        $profileId = $this->profileIdFor($teacher, $workspaceId);

        if ($profileId === null) {
            /*
            | No profile means this person never taught in this workspace, so
            | there is nothing to settle. Zero rather than an exception: an
            | offboarding for a workspace whose owner never took a class is
            | unusual, not invalid.
            */
            return new SettlementStanding;
        }

        /*
        | ⚠️ `SUM(amount_minor)` IS THE WHOLE BALANCE, PAYOUTS INCLUDED. A payout
        | writes a NEGATIVE ledger entry — `RecordTeacherPayout` says so in its own
        | comment, "so the running balance falls to zero rather than leaving the
        | teacher owed what they were just paid" — so subtracting `teacher_payouts`
        | on top of this would count every payment twice and report a teacher who
        | has been paid in full as owing the platform their salary.
        |
        | The ledger is the balance; the statement reads it. Deductions and bonuses
        | have no teaching unit behind them, which is why a total derived from
        | `teaching_units` would omit the first manual adjustment anybody writes.
        */
        $balance = (int) LedgerEntry::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('teacher_profile_id', $profileId)
            ->sum('amount_minor');

        /*
        | ⚠️ AND THE UNITS STILL WAITING FOR THEIR RECORDING ARE OWED TOO, WITH NO
        | LEDGER ENTRY BEHIND THEM. A ledger row is written only when a unit
        | becomes `Accrued` (`TeachingUnitAccrued` → `RecordUnitInLedger`), so a
        | lesson that WAS taught and whose recording has not landed is invisible to
        | the sum above — and letting the teacher leave then is the platform
        | keeping the fee for work it received.
        |
        | `Disputed` is deliberately not counted: no line in this tree ever writes
        | it, so adding it would be a guess about a future state's accounting.
        */
        $pending = (int) TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('teacher_profile_id', $profileId)
            ->where('status', TeachingUnitStatus::PendingPackage->value)
            ->sum('amount_minor');

        return new SettlementStanding(
            owedToTeacherMinor: max(0, $balance) + $pending,
            owedByTeacherMinor: max(0, -$balance),
        );
    }

    public function isCleared(User $teacher, int $workspaceId): bool
    {
        return $this->outstandingFor($teacher, $workspaceId)->isCleared();
    }

    /**
     * ⚠️ SCOPED BY WORKSPACE AS WELL AS BY USER. One person may teach in more than
     * one workspace, and an offboarding winds down exactly one of them — a profile
     * looked up by `user_id` alone would settle the wrong workspace's books and
     * report the wrong answer in both directions.
     */
    private function profileIdFor(User $teacher, int $workspaceId): ?int
    {
        $id = TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $teacher->getKey())
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
