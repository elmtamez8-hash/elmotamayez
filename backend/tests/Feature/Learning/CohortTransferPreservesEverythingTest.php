<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\DecideTransferRequest;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/*
| SC-006 — «ولا يتم فقد اي شيئ».
|
| ⚠️ AND IT IS TRUE BY CONSTRUCTION, WHICH IS THE WHOLE POINT OF THE SCHEMA.
| Membership is a ROW BESIDE the enrolment, never a column in it, so a transfer
| is "close one row, open another" and the progress, the grades and the
| certificate were never in the group to begin with. The assertion below is
| therefore written against the TABLES rather than against a list of fields: a
| field list is a list somebody forgets to extend, and the failure it would miss
| is precisely a new column somebody adds to `enrollments`.
*/

/** Everything about this student that a transfer must not touch. */
function preservationSnapshot(int $studentId): array
{
    return [
        'enrollments' => DB::table('enrollments')->where('student_user_id', $studentId)->get()->toArray(),
        'progress' => DB::table('lesson_progress')
            ->whereIn('enrollment_id', DB::table('enrollments')->where('student_user_id', $studentId)->pluck('id'))
            ->get()->toArray(),
        'certificates' => DB::table('certificates')->where('student_user_id', $studentId)->get()->toArray(),
    ];
}

it('changes nothing outside the cohort tables when a student moves', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    $enrollment = Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())->firstOrFail();

    $enrollment->forceFill(['progress_pct' => 40])->save();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    $before = preservationSnapshot((int) $fx['student']->getKey());

    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student'], 'الأحد أنسب لي');
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], true);

    expect(preservationSnapshot((int) $fx['student']->getKey()))->toEqual($before);

    // And the move really did happen — without this the assertion above is
    // satisfied by an Action that did nothing at all.
    expect(app(CohortDirectory::class)
        ->openMembershipCohortId($fx['student'], (int) $fx['course']->getKey()))
        ->toBe((int) $fx['b']->getKey());

    /*
    | ⚠️ AND THE OLD GROUP GETS ITS PLACE BACK. This shipped wrong and was found
    | by LOOKING at the teacher's screen on a real database: after one student
    | moved, the list read «١ طالب» beside both groups. `members_count` is a
    | column claimed by a conditional UPDATE and never recomputed from a
    | `count()`, so nothing in the product would ever have corrected it — the
    | group would simply have closed itself with nobody in it.
    */
    expect((int) $fx['a']->refresh()->members_count)->toBe(0);
    expect((int) $fx['b']->refresh()->members_count)->toBe(1);
});

/*
| FR-030 · R16 — the seats in the group they LEFT are given up, and only the ones
| that have not happened yet.
|
| ⚠️ THROUGH `CancelBooking`, NEVER A DELETE. A deleted booking row breaks
| `ReconcileCreditBalancesJob`'s standing invariant — one consumption entry per
| seat of a charged session — permanently, with a cause nobody will find. So the
| assertion is on the STATUS of a row that is still there, not on a count.
*/
it('gives up the future seats of the old group and leaves the past alone', function (): void {
    $fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);

    [$future, $past] = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): array {
        $profile = TeacherProfile::factory()->create(['workspace_id' => $fx['workspace']->getKey()]);

        $make = fn (string $when): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $fx['course']->getKey(),
            'cohort_id' => $fx['a']->getKey(),
            'starts_at' => now()->{$when === 'future' ? 'addWeek' : 'subWeek'}(),
            'ends_at' => now()->{$when === 'future' ? 'addWeek' : 'subWeek'}()->addHour(),
            'seats_total' => 5,
            'seats_taken' => 1,
        ]);

        return [$make('future'), $make('past')];
    });

    $this->asGuest();

    $book = fn (ClassSession $session): SessionBooking => SessionBooking::query()->create([
        'workspace_id' => $fx['workspace']->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $fx['student']->getKey(),
        'status' => BookingStatus::Booked,
        'is_billable' => true,
        'booked_at' => now(),
    ]);

    $futureBooking = $book($future);
    $pastBooking = $book($past);

    $request = app(RequestTransfer::class)->handle($fx['b'], $fx['student']);
    app(DecideTransferRequest::class)->handle($request, $fx['owner'], true);

    expect($futureBooking->refresh()->status)->not->toBe(BookingStatus::Booked);

    /*
    | ⚠️ THE PAST SEAT IS UNTOUCHED, AND THAT IS FR-025د. The student was in that
    | room: their attendance is recorded and the recording it produced is theirs
    | by that seat. A timetable decision taken today may not reach back and take
    | either away.
    */
    expect($pastBooking->refresh()->status)->toBe(BookingStatus::Booked);
});
