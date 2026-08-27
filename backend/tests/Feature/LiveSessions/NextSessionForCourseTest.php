<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| `GET /courses/{course}/next-session` — the header (US2 · FR-015 · SC-016).
|
| ⚠️ `join_open` IS THE SERVER'S ANSWER, AND THAT IS THE WHOLE POINT OF THE
| ENDPOINT. The window is a `platform_settings` row an operator tunes and the
| room's own closure sits inside it, so a client computing «is the door open»
| from `starts_at` and a constant offers a button the server refuses — on a
| machine whose clock is wrong, for ever.
*/

/** @return array{student: User, course: Course, session: ClassSession, other: Course} */
function nextSessionFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student): array {
        $make = fn (string $title): Course => Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'title' => $title,
        ]);

        $course = $make('الرياضيات');
        $other = $make('الفيزياء');

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

        $session = fn (Course $for, string $title, $starts, $minutes, ClassSessionStatus $status): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $for->getKey(),
            'title' => $title,
            'status' => $status,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($minutes),
        ]);

        // Sooner than the one below, and cancelled — so «the next one» is a
        // different row depending on whether the status is read.
        $session($course, 'ملغاة', now()->addHour(), 60, ClassSessionStatus::Cancelled);
        $next = $session($course, 'الحصّةُ القادمة', now()->addHours(3), 60, ClassSessionStatus::Scheduled);

        // Sooner than everything, and not this course's.
        $session($other, 'حصّةُ الفيزياء', now()->addMinutes(10), 60, ClassSessionStatus::Scheduled);

        return ['course' => $course, 'other' => $other, 'session' => $next];
    });

    return ['student' => $student, ...$built];
}

beforeEach(function (): void {
    $this->fx = nextSessionFixture();

    Sanctum::actingAs($this->fx['student']);
    $this->asGuest();
});

it('names the next session of this course, skipping a cancelled one and another course\'s', function (): void {
    $payload = $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')
        ->assertOk()
        ->json();

    expect($payload['data']['title'])->toBe('الحصّةُ القادمة')
        ->and($payload['data']['join_open'])->toBeFalse()
        ->and($payload['data']['room_closed'])->toBeFalse()
        // Counted here, not in the browser (SC-016).
        ->and($payload['seconds_until_start'])->toBeGreaterThan(3 * 3600 - 120)
        /*
        | ⚠️ AND SO IS «متى يُفتَح». A boolean answered once at fetch never turns
        | true on a page left open: the student watches the countdown reach
        | «بدأت الآن» while the footer still says the door is shut, for ever,
        | until they reload. Three hours minus the fifteen-minute window.
        */
        ->and($payload['seconds_until_join_open'])->toBeGreaterThan(3 * 3600 - 15 * 60 - 120)
        ->and($payload['seconds_until_join_open'])->toBeLessThan(3 * 3600 - 15 * 60 + 120);
});

/*
| ⚠️ THE SESSION IN PROGRESS IS THE ONE THE HEADER IS FOR. Bounded by `starts_at`
| the header would lose it at the moment it begins — during exactly the minutes
| the join button is the only thing on the page worth pressing.
*/
it('keeps showing a session that has already started', function (): void {
    $this->fx['session']->forceFill([
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(50),
        'status' => ClassSessionStatus::Live,
    ])->save();

    $payload = $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')
        ->assertOk()
        ->json('data');

    expect($payload['title'])->toBe('الحصّةُ القادمة')
        ->and($payload['join_open'])->toBeTrue()
        ->and($payload['seconds_until_start'] ?? 0)->toBe(0);
});

/*
| ⚠️ A ROOM DELIBERATELY CLOSED OUTRANKS A `live` STATUS. `CloseClassSessionJob`
| runs at `ends_at` plus the join window, so a teacher who ends the broadcast
| early leaves the status `live` with the door shut — and the page used to badge
| a finished lesson «جارية» and go on offering «دخول الغرفة», which answers
| «تعذّر الدخول».
*/
it('shuts the door when the room was closed, whatever the status says', function (): void {
    $this->fx['session']->forceFill([
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(50),
        'status' => ClassSessionStatus::Live,
        'room_closed_at' => now(),
    ])->save();

    $payload = $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')
        ->assertOk()
        ->json('data');

    expect($payload['room_closed'])->toBeTrue()
        ->and($payload['join_open'])->toBeFalse();

    // ⚠️ `null`, NOT A LARGE NUMBER. A closed room will not open again, and a
    // client counting down to any number would draw the button eventually.
    expect(
        $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')
            ->json('seconds_until_join_open'),
    )->toBeNull();
});

it('opens the door inside the join window before the start, and not before it', function (): void {
    PlatformSettings::set('sessions.join_window_minutes', 15);

    $this->fx['session']->forceFill([
        'starts_at' => now()->addMinutes(10),
        'ends_at' => now()->addMinutes(70),
    ])->save();

    expect($this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')->json('data.join_open'))
        ->toBeTrue();

    $this->fx['session']->forceFill([
        'starts_at' => now()->addMinutes(40),
        'ends_at' => now()->addMinutes(100),
    ])->save();

    expect($this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')->json('data.join_open'))
        ->toBeFalse();
});

it('answers «no next session» with a null rather than a 404', function (): void {
    ClassSession::query()->withoutWorkspaceScope()->delete();

    $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/next-session')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('refuses a course the reader is not enrolled in', function (): void {
    $this->getJson('/api/v1/courses/'.$this->fx['other']->uuid.'/next-session')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_enrolled');
});

/*
| `GET /courses/{course}/sessions` — the tab (FR-016).
|
| ⚠️ IT EXISTS BECAUSE `GET /class-sessions` ANSWERS A REAL STUDENT `403`, WHICH
| IS NOT A BUG THERE AND IS FATAL TO A TAB BUILT ON IT.
| `ClassSessionPolicy::viewAny()` asks for `SESSIONS_VIEW`, and a student holds
| no spatie team id — a member of no workspace has a null context, so every
| `can()` below it is false. The tab caught that refusal into an empty list and
| told a student with a lesson every week «لا حصص في هذه المادّة بعد».
*/
it('lets a real student read their own course\'s sessions, where the general list refuses them', function (): void {
    // The control, and the reason this endpoint was written: the same reader,
    // the same course, on the route the teacher's calendar uses.
    $this->getJson('/api/v1/class-sessions?course='.$this->fx['course']->uuid)
        ->assertForbidden();

    $titles = collect(
        $this->getJson('/api/v1/courses/'.$this->fx['course']->uuid.'/sessions')->assertOk()->json('data'),
    )->pluck('title')->all();

    expect($titles)->toContain('الحصّةُ القادمة')
        // Cancelled is SHOWN here, unlike in the header: the tab is a calendar,
        // and a lesson that was called off is exactly what a student opens it to
        // find out. The card badges it.
        ->toContain('ملغاة')
        ->not->toContain('حصّةُ الفيزياء');
});

it('refuses the sessions of a course the reader is not enrolled in', function (): void {
    $this->getJson('/api/v1/courses/'.$this->fx['other']->uuid.'/sessions')
        ->assertForbidden()
        ->assertJsonPath('code', 'not_enrolled');
});
