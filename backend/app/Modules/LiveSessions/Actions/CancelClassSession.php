<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher calls a session off.
 *
 * Nobody is charged and nothing enters a counter (FR-026): a cancelled session
 * is not a student's absence and not a teacher's completion. The seats are
 * released as `Released` rather than cancelled — the students did nothing, and
 * filing it against them would put a mark on the wrong person.
 */
class CancelClassSession extends Action
{
    public function handle(ClassSession $session, ?string $reason = null): ClassSession
    {
        if ($session->status->isTerminal()) {
            throw new DomainException('لا يمكن إلغاء حصة منتهية أو ملغاة.');
        }

        DB::transaction(function () use ($session, $reason): void {
            $session->bookings()
                ->where('status', BookingStatus::Booked)
                ->update([
                    'status' => BookingStatus::Released,
                    'is_billable' => false,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'أُلغيت الحصة',
                ]);

            $session->forceFill([
                'status' => ClassSessionStatus::Cancelled,
                'seats_taken' => 0,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
        });

        // Everyone who held a seat hears about it (FR-006). Dispatched after the
        // transaction so a notification never describes a rollback.
        SessionCancelled::dispatch($session, $reason);

        return $session;
    }
}
