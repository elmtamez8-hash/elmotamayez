<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Learning\Events\CohortMembershipOpened;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a membership is opened or closed.
 *
 * Three Actions reach it — a student joining, a teacher approving a transfer,
 * and a teacher moving somebody by hand — and all three have to claim a seat,
 * close whatever was open, and write a history row in one transaction. Written
 * three times it would be three subtly different orders of the same four
 * statements, and the one nobody exercised would be the one that leaks a seat.
 *
 * ⚠️ THE SEAT IS CLAIMED BY ONE ATOMIC CONDITIONAL UPDATE, never `count()` then
 * `insert()` — that is the definition of the race — and never `lockForUpdate()`,
 * which is a no-op on SQLite, so a test written around it passes locally and
 * proves nothing about the MySQL this ships to. It is the seat idiom from 005,
 * and the same one behind `captured_order_id` and `StructureVersion::claim()`.
 *
 * ⚠️ AND THE CLAIM COMES BEFORE THE ROW, WHILE THE CLOSE COMES BETWEEN THEM.
 * If the insert then collides on `unique(student, course, closed_slot)` — two
 * requests for the same student at the same instant — the seat just claimed is
 * given back, because a double tap must not eat a place nobody occupies.
 */
final class CohortMembershipWriter
{
    /**
     * Put this student in this group, closing whatever they were in.
     *
     * @param  string|null  $event  one of {@see CohortMembershipEvent}'s
     *                              constants, or **null to derive** the
     *                              `ASSIGNED`/`TRANSFERRED` pair from whether
     *                              there was a membership to move out of.
     *
     *                              ⚠️ THE DERIVATION LIVES HERE BECAUSE THE
     *                              ANSWER LIVES HERE (٠٣٤ · FR-005). «Has this
     *                              student a membership?» asked by the CALLER is
     *                              a read outside the transaction, and the reply
     *                              can change before the row is written — so an
     *                              argument computed there is a label that
     *                              sometimes disagrees with what actually
     *                              happened. Inside, it is the same `$existing`
     *                              the close and the seat release already turn
     *                              on.
     *
     *                              ⚠️ AND IT FIXES A LIE THAT WAS ALREADY
     *                              SHIPPING: the teacher's «أضِفْ عضواً» button
     *                              hard-coded `TRANSFERRED`, so a student put
     *                              into their FIRST group read «نُقِلت» in their
     *                              own history. `JOINED` is deliberately NOT
     *                              derived — it names the student as the actor,
     *                              which is a fact about who pressed the button
     *                              and not something this method can see.
     * @param  bool  $requireOpen  a student may only enter an `open` group; a
     *                             teacher moving somebody by hand may enter a
     *                             `closed` one, which means "no new joins" and
     *                             not "no more people" (FR-028ط). Neither may
     *                             enter an archived one, and capacity binds both
     *                             — a ceiling is the size of the room.
     */
    public static function open(
        Cohort $cohort,
        User $student,
        ?string $event,
        ?User $actor,
        ?string $reason = null,
        bool $requireOpen = true,
    ): CohortMembership {
        if ($cohort->status === Cohort::ARCHIVED || ($requireOpen && $cohort->status !== Cohort::OPEN)) {
            throw CohortRefusal::closed();
        }

        return DB::transaction(function () use ($cohort, $student, $event, $actor, $reason): CohortMembership {
            $existing = CohortMembership::query()
                ->withoutWorkspaceScope()
                ->where('student_user_id', $student->getKey())
                ->where('course_id', $cohort->course_id)
                ->whereNull('closed_at')
                ->first();

            if ($existing !== null && (int) $existing->cohort_id === (int) $cohort->getKey()) {
                throw CohortRefusal::sameCohort();
            }

            // ٠٣٤ · FR-005 — read from the same row the close below turns on.
            $event ??= $existing === null
                ? CohortMembershipEvent::ASSIGNED
                : CohortMembershipEvent::TRANSFERRED;

            $claimed = Cohort::query()
                ->withoutWorkspaceScope()
                ->whereKey($cohort->getKey())
                ->where(fn ($query) => $query->whereNull('capacity')->orWhereColumn('members_count', '<', 'capacity'))
                ->increment('members_count');

            if ($claimed === 0) {
                throw CohortRefusal::full();
            }

            if ($existing !== null && self::closeRow($existing)) {
                /*
                | ⚠️ THE OLD GROUP GETS ITS PLACE BACK, AND FORGETTING THIS LEAKS
                | A SEAT ON EVERY TRANSFER. Found by looking at a real screen:
                | after one student moved from «السبت» to «الأحد», the teacher's
                | list read «١ طالب» beside BOTH — the counter is the number of
                | people in the room, and an inflated one closes a group nobody
                | is in. Nothing would ever have corrected it: `members_count` is
                | a column claimed by a conditional UPDATE, never a `count()`, so
                | there is no query anywhere that would notice the drift.
                |
                | Guarded by the close having actually happened: zero rows means
                | somebody else closed it and released it already.
                */
                self::releaseSeat((int) $existing->cohort_id);
            }

            try {
                $membership = CohortMembership::query()->create([
                    'workspace_id' => $cohort->workspace_id,
                    'cohort_id' => $cohort->getKey(),
                    'course_id' => $cohort->course_id,
                    'student_user_id' => $student->getKey(),
                    'joined_at' => now(),
                ])->refresh();
            } catch (UniqueConstraintViolationException) {
                // Another request opened a membership for this student between
                // our read and our insert. Give the seat back — ours is the one
                // that loses, and a leaked place is a group that reads full with
                // an empty chair in it.
                self::releaseSeat((int) $cohort->getKey());

                throw CohortRefusal::alreadyMember();
            }

            self::record((int) $cohort->workspace_id, [
                'course_id' => $cohort->course_id,
                'student_user_id' => $student->getKey(),
                'cohort_id' => $cohort->getKey(),
                'from_cohort_id' => $existing?->cohort_id,
                'event' => $event,
                'actor_user_id' => $actor?->getKey(),
                'reason' => $reason,
            ]);

            /*
            | The seats the student holds in the OLD group's sessions are given
            | up by a listener in LiveSessions, never from here: a seat is that
            | module's, and releasing one is `CancelBooking`'s job rather than a
            | delete of our own (R16). The event carries the old cohort id
            | because by the time a listener runs the membership row no longer
            | says which group it was — the same reason `SessionCancelled`
            | carries its seat holders.
            |
            | ⚠️ AND IT FIRES ON A FIRST JOIN TOO, WHICH IT DID NOT UNTIL 052.
            | The old `if ($existing !== null)` was right while the only listener
            | RELEASED seats; that same listener now also SEATS the member in the
            | group they entered, and withholding the event on a first join made
            | the new half reach nobody in the ordinary case — a teacher publishes
            | a term on Sunday, students join through the week, and not one of
            | them is booked into it. `fromCohortId` is null then, and the release
            | arm reads that as «there is nothing to give up».
            */
            event(new CohortMembershipOpened(
                workspaceId: (int) $cohort->workspace_id,
                courseId: (int) $cohort->course_id,
                studentUserId: (int) $student->getKey(),
                fromCohortId: $existing === null ? null : (int) $existing->cohort_id,
                toCohortId: (int) $cohort->getKey(),
            ));

            return $membership;
        });
    }

    /**
     * Take this student out of their group and leave them in none.
     *
     * Used by «إخراج» (FR-028ط). There is deliberately no student-facing "leave":
     * a course whose groups are all full would then have a door out and no door
     * back in — the safety valve opens the curriculum, but it cannot give
     * somebody their place back.
     */
    public static function closeCurrent(
        CohortMembership $membership,
        string $event,
        ?User $actor,
        ?string $reason = null,
    ): void {
        DB::transaction(function () use ($membership, $event, $actor, $reason): void {
            if (! self::closeRow($membership)) {
                return;
            }

            self::releaseSeat((int) $membership->cohort_id);

            self::record((int) $membership->workspace_id, [
                'course_id' => $membership->course_id,
                'student_user_id' => $membership->student_user_id,
                'cohort_id' => null,
                'from_cohort_id' => $membership->cohort_id,
                'event' => $event,
                'actor_user_id' => $actor?->getKey(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * One row in the history, and the ONLY way one is ever written.
     *
     * ⚠️ NEVER A MASS WRITE. `CohortMembershipEvent::booted()` refuses an update
     * and a delete, and a bulk statement retrieves no models — so the guard would
     * be present, tested, and absent exactly where somebody reached past it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(int $workspaceId, array $attributes): CohortMembershipEvent
    {
        return CohortMembershipEvent::query()->create([
            'workspace_id' => $workspaceId,
            ...$attributes,
        ]);
    }

    /**
     * Close one membership with the conditional UPDATE that owns `closed_slot`.
     *
     * The slot goes from the zero sentinel to the row's own id in the same
     * statement that stamps `closed_at`, so there is no window in which the row
     * is closed and still colliding with the next open one. Zero rows affected
     * means somebody else closed it first, which is not an error — it is the
     * other request having already done our work.
     */
    private static function closeRow(CohortMembership $membership): bool
    {
        return CohortMembership::query()
            ->withoutWorkspaceScope()
            ->whereKey($membership->getKey())
            ->whereNull('closed_at')
            ->update([
                'closed_at' => now(),
                'closed_slot' => $membership->getKey(),
            ]) > 0;
    }

    private static function releaseSeat(int $cohortId): void
    {
        Cohort::query()
            ->withoutWorkspaceScope()
            ->whereKey($cohortId)
            ->where('members_count', '>', 0)
            ->decrement('members_count');
    }
}
