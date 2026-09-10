<?php

declare(strict_types=1);

use App\Modules\Community\Models\Announcement;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\StartFocusSession;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| An announcement reaches its scope and nobody else — `FR-043` · `SC-016`.
|
| ⚠️ ON TWO COURSES, NEVER ONE. With a single course every student is in every
| scope, so the whole criterion is satisfied by an implementation that ignores
| the scope column entirely and tells everybody — green, and the exact defect the
| requirement forbids. The second course is what makes the zero measurable.
|
| ⚠️ AND THERE IS NO BARE `Queue::fake()` IN THIS FILE. The fan-out IS a queued
| job, so a bare fake swallows it and every assertion below becomes a confident
| sentence about an empty `notifications` table — the trap already written down
| for the billing suite. The timeline jobs are faked BY NAME so a session fixture
| does not close itself, and the fan-out runs on `sync`.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->maths = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->physics = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->mathsStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->maths, $this->mathsStudent);

    $this->physicsStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->physics, $this->physicsStudent);
});

/** Everything the teacher's screen sends. */
function announcementPayload(array $overrides = []): array
{
    return array_merge([
        'body' => 'حصة الغد تبدأ الساعة الخامسة بدل الرابعة.',
        'scope' => Announcement::SCOPE_ALL,
    ], $overrides);
}

/** Who actually received one, by user id. */
function toldAbout(Announcement $announcement): array
{
    return Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('source_id', $announcement->getKey())
        ->pluck('recipient_user_id')
        ->map(fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();
}

it('reaches every student of one course and nobody in the other', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload([
        'scope' => Announcement::SCOPE_COURSE,
        'scope_uuid' => $this->maths->uuid,
    ]))->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    // 100% of the scope, and zero outside it. Both halves in one assertion: a
    // list comparison fails on the missing recipient AND on the extra one, which
    // two separate counts would not.
    expect(toldAbout($announcement))->toBe([(int) $this->mathsStudent->getKey()]);
});

it('reaches every student in the workspace when the scope is all', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload())
        ->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    $expected = [(int) $this->mathsStudent->getKey(), (int) $this->physicsStudent->getKey()];
    sort($expected);

    expect(toldAbout($announcement))->toBe($expected);
});

it('reaches the seat holders of one session and not the rest of the course', function (): void {
    $session = periodSession($this->workspace, $this->maths, $this->mathsStudent, AttendanceStatus::Present);

    // A classmate ON THE SAME COURSE with no seat. Without them the session scope
    // is indistinguishable from the course scope, and this test would pass over
    // an implementation that ignored the difference.
    $seatless = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->maths, $seatless);

    SessionBooking::factory()->create([
        'class_session_id' => $session->getKey(),
        'student_user_id' => $this->mathsStudent->getKey(),
    ]);

    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload([
        'scope' => Announcement::SCOPE_SESSION,
        'scope_uuid' => $session->uuid,
    ]))->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    expect(toldAbout($announcement))->toBe([(int) $this->mathsStudent->getKey()]);
});

it('never reaches another teacher\'s students', function (): void {
    // ⚠️ A SECOND WORKSPACE, and the first fixture that could show a scope
    // ignoring `workspace_id` at all. Inside one workspace every wrong answer is
    // still one of the teacher's own students.
    [$otherWorkspace, $otherTeacher] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($otherWorkspace, $otherTeacher);
    $otherCourse = Course::factory()->create(['workspace_id' => $otherWorkspace->getKey()]);
    $otherStudent = $this->addWorkspaceMember($otherWorkspace, Roles::STUDENT);
    $this->createEnrollment($otherWorkspace, $otherCourse, $otherStudent);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload())
        ->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $announcement = Announcement::query()->withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

    expect(toldAbout($announcement))->not->toContain((int) $otherStudent->getKey());
});

it('refuses a course from another workspace rather than addressing its students', function (): void {
    [$otherWorkspace, $otherTeacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($otherWorkspace, $otherTeacher);
    $otherCourse = Course::factory()->create(['workspace_id' => $otherWorkspace->getKey()]);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    Sanctum::actingAs($this->teacher);

    // A bare uuid in a request body is an identity probe. `exists:courses,uuid`
    // would have passed this and then published to a stranger's class.
    $this->postJson('/api/v1/manage/announcements', announcementPayload([
        'scope' => Announcement::SCOPE_COURSE,
        'scope_uuid' => $otherCourse->uuid,
    ]))->assertStatus(422);
});

it('sends the urgent kind as its own mandatory type', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload([
        'is_urgent' => true,
    ]))->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $notification = Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('recipient_user_id', $this->mathsStudent->getKey())
        ->firstOrFail();

    expect($notification->type)->toBe(NotificationType::AnnouncementUrgent->value);

    // FR-044. And the valve it actually opens is spec 009's focus mute, which
    // reads `mandatoryValues()` — quiet hours and digesting are external-channel
    // machinery and an announcement never leaves the bell.
    expect(NotificationType::mandatoryValues())->toContain(NotificationType::AnnouncementUrgent->value)
        ->and(NotificationType::mandatoryValues())->not->toContain(NotificationType::Announcement->value);
});

it('carries the teacher\'s words into the notification itself', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload([
        'body' => 'ANNOUNCEMENT_SENTINEL',
    ]))->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    // Nothing else delivers an announcement — there is no student screen for one
    // — so a notification that named the teacher and linked elsewhere would be a
    // notice that announces nothing.
    $notification = Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('recipient_user_id', $this->mathsStudent->getKey())
        ->firstOrFail();

    expect($notification->body)->toContain('ANNOUNCEMENT_SENTINEL');
});

it('reaches a student mid-focus only when it is urgent', function (): void {
    /*
    | `FR-044`, measured through the valve that actually exists rather than
    | asserted about one that does not.
    |
    | ⚠️ «PAST QUIET HOURS AND PAST DIGESTING» IS TRUE OF BOTH TYPES AND SAYS
    | NOTHING ABOUT EITHER. `QuietHours::deferUntil()` returns null immediately
    | for anything that is not an EXTERNAL channel, and an announcement reaches
    | the bell and nothing else — so a test written to the requirement's wording
    | would pass identically over an implementation with no mandatory flag at all.
    |
    | What `isMandatory()` buys an in-app type is spec 009's focus mute, which
    | filters the feed and the unread count on `mandatoryValues()`. That is the
    | difference the student can see, and it is the right difference: an urgent
    | notice interrupts a study session and a routine one waits it out.
    */
    Sanctum::actingAs($this->teacher);

    foreach ([false, true] as $urgent) {
        $uuid = $this->postJson('/api/v1/manage/announcements', announcementPayload([
            'body' => $urgent ? 'أُلغيت حصة الغد.' : 'تذكير بالواجب.',
            'is_urgent' => $urgent,
        ]))->assertCreated()->json('uuid');

        $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();
    }

    Sanctum::actingAs($this->mathsStudent);

    /*
    | ⚠️ MEASURED BY WHICH MESSAGES SURVIVE, NEVER BY A COUNT. Two counts were
    | tried and both were wrong: a literal asserts how many OTHER things the
    | platform happens to send a new student (an enrolment notification, here),
    | and a delta of one assumes everything else in the feed is mandatory — the
    | enrolment notice is not, so the mute takes it too and the delta was two.
    | Both numbers are true of this fixture and about something else entirely.
    | The requirement is about these two messages, so these two are what is asked.
    */
    $types = fn (): array => array_column(
        (array) $this->getJson('/api/v1/notifications')->assertOk()->json('data'),
        'type',
    );

    expect($types())->toContain(NotificationType::Announcement->value)
        ->and($types())->toContain(NotificationType::AnnouncementUrgent->value);

    app(StartFocusSession::class)->handle($this->mathsStudent, 25);

    $feed = $this->getJson('/api/v1/notifications')->assertOk();

    // The urgent one stays; the routine one waits for the session to end.
    expect($feed->json('data'))->not->toBeNull()
        ->and($types())->toContain(NotificationType::AnnouncementUrgent->value)
        ->and($types())->not->toContain(NotificationType::Announcement->value);
});

it('opens no group reply route', function (): void {
    // FR-045 is an absence, and an absence is only guarded by asking for it.
    Sanctum::actingAs($this->mathsStudent);

    $announcement = Announcement::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'author_user_id' => $this->teacher->getKey(),
    ]);

    $this->postJson("/api/v1/announcements/{$announcement->uuid}/replies", ['body' => 'حاضر'])
        ->assertNotFound();
});
