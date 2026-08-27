<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Support\WorkspaceContext;

/*
| SC-011أ · FR-025أ — «الحصّة السابقة» is the previous one IN THE STUDENT'S OWN
| GROUP.
|
| ⚠️ THE WIDER READING IS THE DEFECT, AND ITS FAILURE MODE IS UNANSWERABLE. Two
| groups meeting on different days meant the Sunday student's «previous» could be
| Saturday's class: a booking refused over a session they were never in, whose
| cause CANNOT BE SHOWN to them, because FR-025 hides that session from their
| timetable in the first place. A refusal with no action behind it and no
| readable reason.
|
| ⚠️ AND `(int) null === 0`, which is why the comparison keeps its nulls. Cast on
| both sides, an unassigned session matches every other unassigned session and
| also any group whose id happened to be zero — and, worse, a course that runs
| without groups becomes indistinguishable from one that does.
*/

/** @return array{workspace: mixed, course: mixed, sessions: array<string, ClassSession>} */
function twoGroupTimetable(): array
{
    $fx = cohortFixture();

    $sessions = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): array {
        $profile = TeacherProfile::factory()->create(['workspace_id' => $fx['workspace']->getKey()]);

        $make = fn (?int $cohortId, string $starts): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $fx['course']->getKey(),
            'cohort_id' => $cohortId,
            'starts_at' => now()->parse($starts),
            'ends_at' => now()->parse($starts)->addHour(),
            'status' => ClassSessionStatus::Completed,
        ]);

        return [
            // Saturday's group met most recently — the row the wide reading picks.
            'saturday_old' => $make((int) $fx['a']->getKey(), '2026-08-15 16:00'),
            'saturday_new' => $make((int) $fx['a']->getKey(), '2026-08-22 16:00'),
            'sunday_old' => $make((int) $fx['b']->getKey(), '2026-08-16 18:00'),
            'sunday_target' => $make((int) $fx['b']->getKey(), '2026-08-23 18:00'),
            'ungrouped_old' => $make(null, '2026-08-17 10:00'),
            'ungrouped_target' => $make(null, '2026-08-24 10:00'),
        ];
    });

    return ['workspace' => $fx['workspace'], 'course' => $fx['course'], 'sessions' => $sessions];
}

it('reads the previous session inside the same group, not across the course', function (): void {
    $fx = twoGroupTimetable();
    $this->asGuest();

    $target = (int) $fx['sessions']['sunday_target']->getKey();

    $previous = app(SessionAttendanceDirectory::class)->previousCountableSessionIds([$target]);

    expect($previous[$target])->toBe((int) $fx['sessions']['sunday_old']->getKey());

    // The control: Saturday's is both nearer in time and in the same course, so
    // it is exactly what the pre-021 query returned.
    expect($previous[$target])->not->toBe((int) $fx['sessions']['saturday_new']->getKey());
});

it('matches a session with no group only against a session with no group', function (): void {
    $fx = twoGroupTimetable();
    $this->asGuest();

    $target = (int) $fx['sessions']['ungrouped_target']->getKey();

    $previous = app(SessionAttendanceDirectory::class)->previousCountableSessionIds([$target]);

    expect($previous[$target])->toBe((int) $fx['sessions']['ungrouped_old']->getKey());
});

/*
| FR-025ب — a group in its first week has no previous session, and that is «no
| condition», never «failed the condition». The directory answers `null` and the
| resolver reads a null as open; this is the assertion that would catch a change
| making it answer the nearest session of some OTHER group instead.
*/
it('answers null for the first session a group ever holds', function (): void {
    $fx = twoGroupTimetable();
    $this->asGuest();

    $first = (int) $fx['sessions']['saturday_old']->getKey();

    expect(app(SessionAttendanceDirectory::class)->previousCountableSessionIds([$first])[$first])
        ->toBeNull();
});
