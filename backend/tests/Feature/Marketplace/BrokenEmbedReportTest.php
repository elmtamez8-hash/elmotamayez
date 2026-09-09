<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 032 · US3 · SC-011 — a hundred reports, one alert.
|
| ⚠️ `WithoutMiddleware` IS LOAD-BEARING, NOT TIDINESS. `throttle:public` is
| sixty a minute by address, and SC-011 asks for the HUNDREDTH answer to be
| byte-identical to the first — which a 429 is not. No other file under
| `tests/Feature/Marketplace/` needed this, so it is a new line rather than a
| copied idiom.
*/
uses(WithoutMiddleware::class);

/**
 * @return array{0: Course, 1: Lesson, 2: Workspace}
 */
function reportFixture(
    bool $participates = true,
    string $approval = TeacherProfile::STATUS_APPROVED,
): array {
    static $sequence = 0;

    $workspace = marketplaceWorkspace('Academy', $participates);
    $teacher = marketplaceTeacher($workspace, ['approval_status' => $approval]);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use (
        $workspace, $teacher, &$sequence
    ): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'title' => 'الفيزياء ٣',
            'slug' => 'report-physics-'.(++$sequence),
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(), 'title' => 'فصل',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $lesson = Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => 'الحصّة التعريفيّة', 'type' => 'embed',
            'status' => ContentStatus::Published, 'order' => 1, 'duration_seconds' => 1200,
            'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'is_preview' => true,
        ]);

        return [$course, $lesson, $workspace];
    });
}

/**
 * A real guest request — and the SPATIE TEAM ID CLEARED WITH IT.
 *
 * ⚠️ `asGuest()` REPLACES THE CONTEXT SINGLETON AND DOES NOT TOUCH THE TEAM ID
 * THE FIXTURE PLANTED. Without this line `User::permission()` resolves against a
 * team the platform never sets for a visitor, so the test measures a request
 * that is never made — and passes green over a fan-out that reaches nobody in
 * production.
 */
function asRealGuest(): void
{
    test()->asGuest();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

/** Every alert of this type in the test database — one workspace per case. */
function embedAlerts(): int
{
    return Notification::query()
        ->where('type', NotificationType::LessonLinkReported->value)
        ->count();
}

it('a hundred reports send one notification', function (): void {
    [$course, $lesson] = reportFixture();

    asRealGuest();

    $first = $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    for ($i = 0; $i < 99; $i++) {
        $repeat = $this->postJson(
            "/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report",
        )->assertStatus(202);

        // ⚠️ THE BODY, NOT THE STATUS. A reply that differed once the window was
        // spent would tell an attacker exactly when they were getting warm.
        expect($repeat->getContent())->toBe($first->getContent());
    }

    // One teacher holds `courses.update` in this fixture, so one row.
    expect(embedAlerts())->toBe(1);
});

it('reaches the teacher AND their assistant, by permission and not by role name', function (): void {
    [$course, $lesson, $workspace] = reportFixture();

    // `assistant-teacher` holds `courses.update`, which is what «المدرّس ومن
    // يعينه» means in FR-020 — a rule written against a role NAME is one rename
    // away from nothing.
    $this->addWorkspaceMember($workspace, 'assistant-teacher');

    asRealGuest();

    $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    expect(embedAlerts())->toBe(2);
});

it('lets a SECOND fingerprint through inside the window — the mirror, and it is mandatory', function (): void {
    /*
    | ⛔ WITHOUT THIS, ONE FALSE TAP TURNS OFF THE ONLY SENSOR THERE IS FOR A DAY.
    |
    | The stamp is written by a stranger with no account. Its precedent
    | `notified_dormant_at` is stamped by us about us; here the sender is a
    | potential adversary, so the window alone would be a mute button pointing
    | outward.
    */
    [$course, $lesson] = reportFixture();

    asRealGuest();

    for ($i = 0; $i < 100; $i++) {
        $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
            ->assertStatus(202);
    }

    expect(embedAlerts())->toBe(1);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    // A second and final alert: the honest witness gets through, and a third
    // fingerprint does not turn one broken lesson into a flood.
    expect(embedAlerts())->toBe(2);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    expect(embedAlerts())->toBe(2);
});

it('alerts again once the window has run out', function (): void {
    [$course, $lesson] = reportFixture();

    asRealGuest();

    $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report");

    expect(embedAlerts())->toBe(1);

    Carbon::setTestNow(Carbon::now()->addHours(25));

    $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report");

    expect(embedAlerts())->toBe(2);

    Carbon::setTestNow();
});

it('accepts a report about a teacher who is not publicly listed', function (): void {
    /*
    | ⛔ FR-021. The ENROLLED student sees the break too, from
    | `/learn/lessons/…`, and their teacher may never have applied to the
    | marketplace. A door restricted to listed teachers would drop that report in
    | silence, for ever — and the constant reply makes the loss invisible from
    | both sides.
    */
    [$course, $lesson] = reportFixture(approval: TeacherProfile::STATUS_PENDING);

    asRealGuest();

    $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    expect(embedAlerts())->toBe(1);
});

it('answers a lesson that does not exist exactly as it answers one that does', function (): void {
    [$course, $lesson] = reportFixture();

    asRealGuest();

    $real = $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202);

    $ghost = $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/".Str::uuid().'/report')
        ->assertStatus(202);

    expect($ghost->getContent())->toBe($real->getContent());
});

it('does not promise that the teacher was told', function (): void {
    [$course, $lesson] = reportFixture();

    asRealGuest();

    $body = $this->postJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}/report")
        ->assertStatus(202)
        ->json('message');

    // ⛔ «أبلغنا المدرّس» is a lie in the duplicate branch and in the
    // no-such-thing branch. The same rule that forbids printing «٠ دقيقة».
    expect($body)->toBe('شكراً لك. سُجِّلت ملاحظتك.')
        ->and($body)->not->toContain('أبلغنا');
});
