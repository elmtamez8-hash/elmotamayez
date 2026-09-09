<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Turns the weekly availability the marketplace has published since spec 001
 * into dated sessions.
 *
 * The slots are not rebuilt or replaced — they were always the source, they
 * simply had no producer, which is why the public calendar has been unbookable
 * for four phases.
 *
 * Skipped slots are returned WITH their reason. A generator that silently drops
 * clashes leaves a teacher believing their week is full when half of it never
 * got created.
 */
class GenerateSessionsFromAvailability extends Action
{
    public function __construct(
        private readonly ScheduleClassSession $schedule,
    ) {}

    /**
     * @param  list<string>  $slotUuids  empty means every slot the teacher has
     * @return array{created: list<ClassSession>, skipped: list<array{starts_at: string, reason: string}>}
     */
    public function handle(
        TeacherProfile $teacher,
        Course $course,
        CarbonImmutable $from,
        CarbonImmutable $to,
        User $actor,
        array $slotUuids = [],
        int $seatsTotal = 1,
        ClassSessionType $type = ClassSessionType::Individual,
        ?string $title = null,
        /*
        | ⚠️ THE GROUP THE GENERATED SESSIONS BELONG TO. Without it every session
        | this action produces is born with no group — and the teacher's first
        | group then takes all of them out of every student's discovery list at a
        | stroke, with the «حصص محجوبة» panel refusing to file the ones already
        | taught. `ScheduleClassSession` refuses a GROUP session with no group;
        | an individual slot passes null and gets its one-seat group at booking.
        */
        ?int $cohortId = null,
    ): array {
        $slots = AvailabilitySlot::query()
            ->where('teacher_profile_id', $teacher->getKey())
            ->when($slotUuids !== [], fn ($query) => $query->whereIn('uuid', $slotUuids))
            ->get();

        $created = [];
        $skipped = [];

        foreach ($this->occurrences($slots, $from, $to) as $occurrence) {
            $data = new ScheduleSessionData(
                teacherProfileId: (int) $teacher->getKey(),
                // Required since Q-7: the generator produces sessions OF a
                // course, because the price is the course's. A bulk generate
                // that left it null would create a week of sessions no student
                // could ever be charged for.
                courseId: (int) $course->getKey(),
                title: $title ?? $course->title,
                type: $type,
                startsAt: $occurrence['starts_at'],
                durationMinutes: $occurrence['duration_minutes'],
                seatsTotal: $seatsTotal,
                cohortId: $cohortId,
            );

            try {
                $created[] = $this->schedule->handle($data, $actor);
            } catch (DomainException $e) {
                // An overlap or a freeze is an expected outcome of a bulk
                // generate, not a failure of the whole run.
                $skipped[] = [
                    'starts_at' => $occurrence['starts_at']->toIso8601String(),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Every dated occurrence of these weekly slots inside the range.
     *
     * @param  Collection<int, AvailabilitySlot>  $slots
     * @return list<array{starts_at: CarbonImmutable, duration_minutes: int}>
     */
    private function occurrences($slots, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $occurrences = [];

        // Slots store UTC times, so the walk is in UTC too. Doing it in local
        // time and converting afterwards is what makes a session drift by an
        // hour across a daylight-saving boundary.
        for ($day = $from->utc()->startOfDay(); $day <= $to; $day = $day->addDay()) {
            foreach ($slots as $slot) {
                if ((int) $day->format('w') !== $slot->day_of_week) {
                    continue;
                }

                $startsAt = CarbonImmutable::parse($day->toDateString().' '.$slot->start_time, 'UTC');
                $endsAt = CarbonImmutable::parse($day->toDateString().' '.$slot->end_time, 'UTC');

                if ($startsAt->isPast()) {
                    continue;
                }

                $occurrences[] = [
                    'starts_at' => $startsAt,
                    'duration_minutes' => (int) $startsAt->diffInMinutes($endsAt),
                ];
            }
        }

        return $occurrences;
    }
}
