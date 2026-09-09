<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

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
