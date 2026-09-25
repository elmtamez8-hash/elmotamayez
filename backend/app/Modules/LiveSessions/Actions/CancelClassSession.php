<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionCreditHolds;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher calls a session off.
 *
 * Nobody is charged and nothing enters a counter (FR-026): a cancelled session
 * is not a student's absence and not a teacher's completion. The seats are
 * released as `Released` rather than cancelled — the students did nothing, and
 * filing it against them would put a mark on the wrong person.
 *
 * Only a session that has not happened yet can be called off: `Scheduled`, or
 * `Suspended` by a freeze. The other two non-terminal states are refused, each
 * for its own reason:
 *
 * - `Live` — the room is open and students are inside it. Cancelling would
 *   release the seats of people sitting in the lesson and leave the room running
 *   with nobody entitled to it (this Action never stamps `room_closed_at`). A
 *   lesson in progress is ended from the room; the frontend has only ever
 *   offered «إلغاء» on a scheduled session.
 * - `Interrupted` — the verdict `AbandonClassSession` writes when the teacher
 *   never opened the room. It sits in the denominator of the teacher's
 *   attendance rate while `Cancelled` sits in neither side, so cancelling it
 *   afterwards would launder a no-show out of the very figure it belongs in.
 */
class CancelClassSession extends Action
{
    /**
     * The only states a cancellation may start from. Both the friendly refusal
     * below and the claim inside the transaction read THIS list, so the sentence
     * on the screen and the guard at the door cannot disagree.
     *
     * @var list<ClassSessionStatus>
     */
    private const CANCELLABLE = [ClassSessionStatus::Scheduled, ClassSessionStatus::Suspended];

    public function __construct(private readonly SessionCreditHolds $holds) {}

    public function handle(ClassSession $session, ?string $reason = null): ClassSession
    {
        // Advisory: the in-memory model may be stale. These give the teacher the
        // right sentence; the claim below is what actually holds the door.
        if ($session->status->isTerminal()) {
            throw new DomainException('لا يمكن إلغاء حصة منتهية أو ملغاة.');
        }

        if ($session->status === ClassSessionStatus::Live) {
            throw new DomainException('لا يمكن إلغاء حصة جارية؛ أنهِها من الغرفة.');
        }

        if (! in_array($session->status, self::CANCELLABLE, true)) {
            throw new DomainException('لا يمكن إلغاء هذه الحصة في حالتها الحالية.');
        }

        $cancelledAt = now();
        /** @var list<int> $seatHolderIds */
        $seatHolderIds = [];

        DB::transaction(function () use ($session, $reason, $cancelledAt, &$seatHolderIds): void {
            /*
            | ⛔ THE STATUS CHANGE IS THE CLAIM — ONE CONDITIONAL UPDATE, AND IT
            | COMES FIRST.
            |
            | This used to read `isTerminal()` on the loaded model and then
            | `forceFill(...)->save()` unconditionally. `CloseClassSession` moves
            | the same row with conditional UPDATEs from two senders, so a
            | cancellation that loaded the session a moment before the close could
            | write `cancelled` over a session that had just been COMPLETED and
            | DELIVERED — releasing every seat after the lesson was taught, after
            | `SessionDelivered` had charged them and paid the teacher. The state
            | on record would then contradict the money.
            |
            | Thrown from INSIDE the transaction, so nothing below it is written:
            | no seat released, no credit freed, no notification. Never
            | `lockForUpdate()`, a no-op on SQLite.
            */
            $claimed = DB::table('class_sessions')
                ->where('id', $session->getKey())
                ->whereIn('status', array_map(
                    static fn (ClassSessionStatus $status): string => $status->value,
                    self::CANCELLABLE,
                ))
                ->update([
                    'status' => ClassSessionStatus::Cancelled->value,
                    'seats_taken' => 0,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_reason' => $reason,
                    'updated_at' => $cancelledAt,
                ]);

            if ($claimed !== 1) {
                throw new DomainException('تغيّرت حالة هذه الحصة للتوّ، فلم تُلغَ. حدّث الصفحة.');
            }

            // Read while it is still true. One statement later these rows are
            // released and indistinguishable from a seat the student gave back
            // last month.
            $seatHolderIds = array_values($session->bookings()
                ->where('status', BookingStatus::Booked)
                ->pluck('student_user_id')
                ->map(fn ($id): int => (int) $id)
                ->all());

            $session->bookings()
                ->where('status', BookingStatus::Booked)
                ->update([
                    'status' => BookingStatus::Released,
                    'is_billable' => false,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_reason' => 'أُلغيت الحصة',
                ]);

            // ٠٣٥ · T060 — every frozen credit on this hour comes back, in TWO
            // statements whatever the seat count: the teacher called the lesson
            // off, so no seat here is billable and none is waiting on a verdict.
            $this->holds->release((int) $session->getKey());
        });

        // The in-memory model does not learn about a conditional UPDATE, and the
        // controller renders it.
        $session->forceFill([
            'status' => ClassSessionStatus::Cancelled,
            'seats_taken' => 0,
            'cancelled_at' => $cancelledAt,
            'cancellation_reason' => $reason,
        ])->syncOriginal();

        // Everyone who held a seat hears about it (FR-006). Dispatched after the
        // transaction so a notification never describes a rollback.
        SessionCancelled::dispatch($session, $reason, $seatHolderIds);

        return $session;
    }
}
