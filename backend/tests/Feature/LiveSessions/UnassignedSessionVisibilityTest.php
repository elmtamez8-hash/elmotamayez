<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| SC-011ب · Q3 — FR-025ج · FR-025د · FR-025هـ.
|
| ⚠️ THE HIDING IS A JUDGEMENT ABOUT WHAT IS ON OFFER, NEVER ABOUT A RIGHT
| ALREADY ACQUIRED. An unassigned session disappears from DISCOVERY the moment a
| course has groups — and stays fully visible to whoever already holds a seat in
| it, because they booked it, paid for it, may have attended it, and the
| recording it produced is theirs by that seat.
|
| Those are two different endpoints, which is what makes FR-025د true by
| construction: `ClassSessionController@index` answers "what can I book" and
| `GetStudentSchedule` answers "what did I book". Only the first one is touched.
*/

/** @return array{fx: array<string, mixed>, assigned: ClassSession, orphan: ClassSession, past: ClassSession} */
function unassignedFixture(): array
{
    $fx = cohortFixture();

    $sessions = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): array {
        $profile = TeacherProfile::factory()->create(['workspace_id' => $fx['workspace']->getKey()]);

        $make = fn (?int $cohortId, string $title, bool $future = true): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $fx['course']->getKey(),
            'cohort_id' => $cohortId,
            'title' => $title,
            'starts_at' => $future ? now()->addWeek() : now()->subWeek(),
            'ends_at' => $future ? now()->addWeek()->addHour() : now()->subWeek()->addHour(),
            'seats_total' => 5,
            'seats_taken' => 0,
        ]);

        return [
            'assigned' => $make((int) $fx['a']->getKey(), 'حصة السبت'),
            'orphan' => $make(null, 'حصة بلا مجموعة'),
            'past' => $make(null, 'حصة قديمة بلا مجموعة', future: false),
        ];
    });

    return ['fx' => $fx, ...$sessions];
}

it('hides an unassigned session from discovery once the course has groups', function (): void {
    $f = unassignedFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($f['fx']['a'], $f['fx']['student']);

    Sanctum::actingAs($f['fx']['student']);
    $this->asGuest();

    $courseUuid = $f['fx']['course']->uuid;

    /*
    | ⚠️ `/courses/{course}/sessions`, NOT `/class-sessions` — the latter answers
    | a REAL student `403` (no workspace, no team id, no `SESSIONS_VIEW`), so a
    | test written against it would be measuring a refusal rather than a filter,
    | and the filter itself would be guarding a door no student can open.
    */
    $titles = collect($this->getJson('/api/v1/courses/'.$courseUuid.'/sessions')->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles)->toContain('حصة السبت');
    expect($titles)->not->toContain('حصة بلا مجموعة');
});

it('hides another group\'s session from a student who is not in it', function (): void {
    $f = unassignedFixture();
    $this->asGuest();

    // In group B; the only assigned session belongs to group A.
    app(JoinCohort::class)->handle($f['fx']['b'], $f['fx']['student']);

    Sanctum::actingAs($f['fx']['student']);
    $this->asGuest();

    $courseUuid = $f['fx']['course']->uuid;

    /*
    | ⚠️ `/courses/{course}/sessions`, NOT `/class-sessions` — the latter answers
    | a REAL student `403` (no workspace, no team id, no `SESSIONS_VIEW`), so a
    | test written against it would be measuring a refusal rather than a filter,
    | and the filter itself would be guarding a door no student can open.
    */
    $titles = collect($this->getJson('/api/v1/courses/'.$courseUuid.'/sessions')->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles)->not->toContain('حصة السبت');
});

/*
| ⚠️ FR-025د — THE SEAT HOLDER KEEPS SEEING IT. This is the assertion that fails
| if somebody "tidies up" by adding the same cohort filter to `GetStudentSchedule`.
*/
it('keeps an unassigned session in «حصصي» for whoever holds a seat in it', function (): void {
    $f = unassignedFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($f['fx']['a'], $f['fx']['student']);

    SessionBooking::query()->create([
        'workspace_id' => $f['fx']['workspace']->getKey(),
        'class_session_id' => $f['orphan']->getKey(),
        'student_user_id' => $f['fx']['student']->getKey(),
        'status' => BookingStatus::Booked,
        'is_billable' => true,
        'booked_at' => now(),
    ]);

    Sanctum::actingAs($f['fx']['student']);
    $this->asGuest();

    $payload = $this->getJson('/api/v1/schedule')->assertOk()->json();
    $rows = $payload['data'] ?? $payload;

    expect(json_encode($rows, JSON_UNESCAPED_UNICODE))->toContain('حصة بلا مجموعة');
});

/*
| FR-025هـ — ⚠️ A HIDING THE OWNER OF THE TIMETABLE DOES NOT KNOW ABOUT IS A
| SILENT LOSS. Creating the first group removes every existing session from
| discovery at a stroke; without this list the students simply stop seeing
| classes and nothing says why.
*/
it('shows the teacher what is hidden and assigns the batch in one request', function (): void {
    $f = unassignedFixture();

    Sanctum::actingAs($f['fx']['owner']);
    $this->setCurrentWorkspace($f['fx']['workspace'], $f['fx']['owner']);

    $listing = $this->getJson('/api/v1/manage/courses/'.$f['fx']['course']->uuid.'/unassigned-sessions')
        ->assertOk()->json();

    expect($listing['meta'])->toMatchArray([
        'total_hidden' => 2,
        'assignable' => 1,
        // ⚠️ COUNTED, NOT OFFERED. FR-025و refuses to assign a session that has
        // started or ended, so listing one beside an «إسناد» button is a button
        // that answers with a refusal — but «two hidden, one already taught» is
        // the honest number and «one» is not.
        'already_held' => 1,
    ]);

    $this->postJson('/api/v1/manage/courses/'.$f['fx']['course']->uuid.'/assign-sessions', [
        'cohort_uuid' => $f['fx']['a']->uuid,
        'session_uuids' => [$f['orphan']->uuid],
    ])->assertOk()->assertJsonPath('assigned', 1);

    expect((int) $f['orphan']->refresh()->cohort_id)->toBe((int) $f['fx']['a']->getKey());

    $this->getJson('/api/v1/manage/courses/'.$f['fx']['course']->uuid.'/unassigned-sessions')
        ->assertOk()->assertJsonPath('meta.assignable', 0);
});

it('refuses to file a session that has already been held', function (): void {
    $f = unassignedFixture();

    Sanctum::actingAs($f['fx']['owner']);
    $this->setCurrentWorkspace($f['fx']['workspace'], $f['fx']['owner']);

    $this->postJson('/api/v1/manage/courses/'.$f['fx']['course']->uuid.'/assign-sessions', [
        'cohort_uuid' => $f['fx']['a']->uuid,
        'session_uuids' => [$f['past']->uuid],
    ])->assertStatus(422);

    expect($f['past']->refresh()->cohort_id)->toBeNull();
});

/*
| ⚠️ AND A COURSE WITH NO GROUPS IS UNTOUCHED (FR-036 · SC-009). This is the
| assertion that fails if the filter is ever written as a bare `whereNotNull` —
| which would empty the timetable of every course in the product overnight, since
| every session that exists today carries `cohort_id = null`.
*/
it('leaves a course with no groups showing exactly what it showed before', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $session = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student): ClassSession {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
        ]);

        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        $profile = TeacherProfile::factory()->create(['workspace_id' => $workspace->getKey()]);

        return ClassSession::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $course->getKey(),
            'title' => 'حصة كورس بلا مجموعات',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
        ]);
    });

    Sanctum::actingAs($student);
    $this->asGuest();

    $courseUuid = $session->course->uuid;

    /*
    | ⚠️ `/courses/{course}/sessions`, NOT `/class-sessions` — the latter answers
    | a REAL student `403` (no workspace, no team id, no `SESSIONS_VIEW`), so a
    | test written against it would be measuring a refusal rather than a filter,
    | and the filter itself would be guarding a door no student can open.
    */
    $titles = collect($this->getJson('/api/v1/courses/'.$courseUuid.'/sessions')->assertOk()->json('data'))
        ->pluck('title')->all();

    expect($titles)->toContain($session->title);
});
