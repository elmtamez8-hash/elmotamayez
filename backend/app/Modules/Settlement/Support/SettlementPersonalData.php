<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Settlement's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class SettlementPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'settlement';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['teacher_earnings'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | ⚠️ THE TEACHER'S SIDE ONLY, AND `teaching_units.student_user_id` IS NOT A
        | DOOR INTO IT. Every row here names a student as well as a teacher, so the
        | obvious walk — "rows mentioning this person" — would hand a student the
        | amount their teacher was paid for teaching them, and two package sizes
        | later the platform's own margin with it. No money reaches a student's
        | screen anywhere in this product, and an archive is a screen with a longer
        | life. `ContextIsolationTest` fails the build over the same crossing.
        |
        | A guardian therefore receives nothing from this module, which needs no
        | gate: a child holds no teacher profile, so the predicate finds no rows.
        */
        $profileIds = TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->where('user_id', $subject->user->getKey())
            ->pluck('id')
            ->all();

        if ($profileIds === []) {
            return;
        }

        yield from ExportWalk::keyed(
            'teacher_earnings',
            TeachingUnit::query()->withoutWorkspaceScope()->whereIn('teacher_profile_id', $profileIds),
            fn (TeachingUnit $unit): array => array_intersect_key([
                'uuid' => $unit->uuid,
                'session_type' => $unit->session_type,
                'status' => $unit->status,
                'basis' => $unit->basis,
                'amount_minor' => $unit->amount_minor,
                'currency' => $unit->currency,
                'frozen_seats' => $unit->frozen_seats,
                'pending_reason' => $unit->pending_reason,
                'recording_fault' => $unit->recording_fault,
                'delivered_at' => ExportWalk::at($unit->delivered_at),
                'accrued_at' => ExportWalk::at($unit->accrued_at),
            ], array_flip(TeacherFieldAllowlist::UNIT)),
        );

        /*
        | The ledger is the balance, and it carries what no unit does: a deduction
        | and a bonus have no teaching unit behind them. A statement derived from
        | units alone omits the first manual adjustment anybody writes, which is
        | exactly the completeness FR-016 asks about.
        */
        yield from ExportWalk::keyed(
            'teacher_earnings',
            LedgerEntry::query()->withoutWorkspaceScope()->whereIn('teacher_profile_id', $profileIds),
            fn (LedgerEntry $entry): array => [
                'uuid' => $entry->uuid,
                'type' => $entry->type,
                'amount_minor' => $entry->amount_minor,
                'currency' => $entry->currency,
                'reason' => $entry->reason,
                'created_at' => ExportWalk::at($entry->created_at),
            ],
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        // TODO(013-US4): erase or anonymise this module's rows for the subject.
        return 0;
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
