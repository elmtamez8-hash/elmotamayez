<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionClash;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Edits a scheduled session.
 *
 * The type is frozen once anybody has booked (FR-001ب). Pricing in 006 and
 * payout in 014 differ by type in kind, so flipping a booked group session to
 * individual silently reprices seats people already hold.
 */
class UpdateClassSession extends Action
{
    /** @param array<string, mixed> $attributes */
    public function handle(ClassSession $session, array $attributes): ClassSession
    {
        if ($session->status->isTerminal()) {
            throw new DomainException('لا يمكن تعديل حصة منتهية أو ملغاة.');
        }

        /*
        | ٠٣٥ · T075 · FR-029 — THE CLOCK AND THE DURATION ARE FROZEN WHILE THE
        | LESSON IS BEING TAUGHT.
        |
        | The existing guard above covers a TERMINAL session and stops there, so
        | this was open the whole time the room was live — and the duration is not
        | a label. The stay bar is half of it (٠٣٥ · FR-005) and is read when the
        | register closes, so a teacher who drops a sixty-minute lesson to ten in
        | its fiftieth minute moves that bar from thirty minutes to five: everyone
        | who looked in briefly is charged a credit, and the teacher is paid for
        | every one of them. `starts_at` is the same lever from the other end — it
        | also derives the cancellation deadline, which is what decides who is
        | exempt when the hour is judged.
        |
        | ⛔ THE CONDITION IS THE STATUS, NEVER `room_opened_at`. The two agree in a
        | real database, where only `OpenBroadcastRoom` writes `Live` and it writes
        | both in one statement — but fixtures across the suite stamp `live` with
        | no timestamp, so the second spelling turns forty existing tests into
        | claims about a lesson that never happened.
        |
        | ⛔ AND IT NAMES THE TWO FIELDS RATHER THAN LOCKING THE ACTION. There is a
        | third caller with no FormRequest above it: `DecideSessionRescheduleRequest`
        | reaches this Action directly with `starts_at`. A blanket refusal would
        | take the reschedule decision down with it — and a live session really is
        | one the decision may not move, so that call refuses here on purpose while
        | every other edit it makes goes through.
        */
        if ($session->status === ClassSessionStatus::Live) {
            foreach (['starts_at', 'duration_minutes'] as $frozen) {
                if (array_key_exists($frozen, $attributes)) {
                    throw new DomainException('لا يمكن تغيير موعد الحصة أو مدتها وهي جارية.');
                }
            }
        }

        if (isset($attributes['type'])) {
            $this->assertTypeMayChange($session, ClassSessionType::from((string) $attributes['type']));
        }

        if (isset($attributes['seats_total'])) {
            $this->assertSeatsNotBelowBooked($session, (int) $attributes['seats_total']);
        }

        if (isset($attributes['starts_at']) || isset($attributes['duration_minutes'])) {
            $startsAt = isset($attributes['starts_at'])
                ? CarbonImmutable::parse((string) $attributes['starts_at'])->utc()
                : CarbonImmutable::instance($session->starts_at);
            $duration = (int) ($attributes['duration_minutes'] ?? $session->duration_minutes);

            $attributes['starts_at'] = $startsAt;
            $attributes['ends_at'] = $startsAt->addMinutes($duration);
            $attributes['duration_minutes'] = $duration;

            /*
            | ⚠️ THE TWO RULES SCHEDULING HAS ALWAYS ENFORCED, AND EDITING NEVER
            | DID. `ScheduleClassSession` refuses an overlap and a freeze period;
            | this action moved `starts_at` with neither check, so the rule held
            | while a session was created and evaporated the moment one was
            | moved — which is how one group's Saturday lands on top of another
            | group's, two rooms of students told to turn up to the same hour and
            | nothing anywhere saying so. One spelling now, in {@see SessionClash}.
            */
            SessionClash::assertFree(
                (int) $session->teacher_profile_id,
                $startsAt,
                $startsAt->addMinutes($duration),
                (int) $session->getKey(),
            );

            SessionClash::assertNotFrozen($startsAt);
        }

        $session->fill($attributes)->save();

        return $session->refresh();
    }

    private function assertTypeMayChange(ClassSession $session, ClassSessionType $type): void
    {
        if ($type === $session->type) {
            return;
        }

        if ($session->seats_taken > 0) {
            throw new DomainException('لا يمكن تغيير نوع الحصة بعد وجود حجز فيها.');
        }
    }

    private function assertSeatsNotBelowBooked(ClassSession $session, int $seats): void
    {
        if ($seats < $session->seats_taken) {
            throw new DomainException('عدد المقاعد أقل من عدد المحجوز فعلاً.');
        }
    }
}
