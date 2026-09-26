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

        /*
        | ⛔ THE WALK IS ON EACH SLOT'S OWN CALENDAR, NOT IN UTC. A slot is
        | wall-clock time in the teacher's zone (2026-09-25): «Tuesday 17:00,
        | Africa/Cairo». Walking UTC days with one fixed UTC time of day is exactly
        | what moved a Cairo teacher's lesson from 17:00 to 16:00 on 2026-10-29,
        | when Egypt left daylight saving and the stored UTC row did not. Each
        | date is parsed IN THE SLOT'S ZONE, so the offset is the one that date
        | actually has.
        |
        | `$from` and `$to` are read as the DATES the teacher picked (the form
        | sends `YYYY-MM-DD`), inclusive at both ends, on the teacher's calendar —
        | which is what «generate from the 1st to the 30th» means to the person
        | pressing it.
        */
        $first = $from->toDateString();
        $last = $to->toDateString();

        foreach ($slots as $slot) {
            for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
                $occurrence = $slot->occurrenceOn($day->toDateString());

                if ($occurrence === null || $occurrence['starts_at']->isPast()) {
                    continue;
                }

                $occurrences[] = [
                    'starts_at' => $occurrence['starts_at'],
                    'duration_minutes' => (int) $occurrence['starts_at']->diffInMinutes($occurrence['ends_at']),
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $occurrences;
    }
}
