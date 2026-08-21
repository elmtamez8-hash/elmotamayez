<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\ExecuteTeacherOffboarding;
use App\Modules\Compliance\Actions\RequestTeacherOffboarding;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * FR-035 — the public listing stops and the PAID ACCESS DOES NOT.
 *
 * ⚠️ THESE ARE TWO MECHANISMS AND THE WHOLE REQUIREMENT IS THAT THEY STAY APART.
 * `publiclyListed()` reads `is_publicly_listed`; `Enrollment::accessTo()` reads the
 * course tree. Nothing joins them, which is why FR-035 needed no new work in
 * `Learning` at all — and why a single test asserting only the first half would
 * pass just as well against an implementation that took a course away from
 * somebody who bought it.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $this->workspace = $workspace;
    $this->teacher = $owner;

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'is_publicly_listed' => true,
    ]);

    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);
});

function departingCourse(int $workspaceId): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'is_sequential' => false,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'الدرس', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'x', 'order' => 0,
    ]);

    return [$course, $lesson];
}

it('pulls the public listing the moment the teacher asks to leave', function (): void {
    Queue::fake();

    expect($this->profile->fresh()?->is_publicly_listed)->toBeTrue();

    app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    /*
     * ⚠️ AT REQUEST, NOT AT COMPLETION. The contract table put unlisting under
     * completion and FR-035's «فوراً» reads that way — but an exit serves a month
     * of notice, and a profile still on the marketplace through it signs up NEW
     * students with somebody who is leaving. The listener runs on both events;
     * unlisting twice is one idempotent UPDATE.
     */
    expect($this->profile->fresh()?->is_publicly_listed)->toBeFalse();
});

it('leaves a paying student their lesson after the exit completes', function (): void {
    Queue::fake();

    [$course, $lesson] = departingCourse((int) $this->workspace->getKey());

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $enrollment = Enrollment::create([
        'workspace_id' => $this->workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        // The remaining term FR-035 is about.
        'expires_at' => now()->addMonths(3),
    ]);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding->refresh(), $this->officer);

    Sanctum::actingAs($student);

    // The lesson they paid for still opens — the enrolment is what entitles them,
    // and nothing in the exit touches it.
    $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson->uuid}")
        ->assertOk();
});

/*
 * ⚠️ FR-036 — THE RECORDINGS' RETENTION COMES FROM THE STUDENTS' RIGHTS.
 *
 * Not from the exit date and not from the lesson date. A student three months into
 * a paid term keeps the recordings for those three months; the floor underneath is
 * the category's own retention, which is what an open-ended enrolment gets. The
 * date is written and NOTHING is deleted here — the retention sweep is the only
 * deletion path, and it is what archives the lesson and resyncs the course.
 */
it('dates the recordings from the last paid access rather than from the exit', function (): void {
    Queue::fake();

    [$course] = departingCourse((int) $this->workspace->getKey());

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $expiry = now()->addYears(4)->startOfDay();

    Enrollment::create([
        'workspace_id' => $this->workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        // Deliberately past the 730-day floor: the term is what decides, not the
        // catalogue, and a test whose term ends sooner cannot tell the two apart.
        'expires_at' => $expiry,
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'course_id' => $course->id,
    ]);

    $asset = MediaAsset::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => ClassSession::class,
        'owner_id' => $session->getKey(),
        'provider' => 'local',
        'provider_asset_id' => 'vid-1',
        'kind' => MediaKind::Video,
        'role' => MediaRole::Primary,
        'status' => 'ready',
        'original_filename' => 'session.mp4',
    ]);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding->refresh(), $this->officer);

    $asset->refresh();

    expect($asset->retain_until)->not->toBeNull()
        ->and($asset->retain_until?->toDateString())->toBe($expiry->toDateString())
        // And nothing was destroyed: the sweep is the only path that deletes.
        ->and($asset->archived_at)->toBeNull();
});

/*
 * The mirror case, and the one where a naive "latest expiry" reads null and
 * deletes everything: an enrolment with no end date is access that does NOT
 * expire, which is the default shape here.
 */
it('falls back to the category s own retention when access has no end date', function (): void {
    Queue::fake();

    [$course] = departingCourse((int) $this->workspace->getKey());

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Enrollment::create([
        'workspace_id' => $this->workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        'expires_at' => null,
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'course_id' => $course->id,
    ]);

    $asset = MediaAsset::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => ClassSession::class,
        'owner_id' => $session->getKey(),
        'provider' => 'local',
        'provider_asset_id' => 'vid-2',
        'kind' => MediaKind::Video,
        'role' => MediaRole::Primary,
        'status' => 'ready',
        'original_filename' => 'session.mp4',
    ]);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding->refresh(), $this->officer);

    expect($asset->refresh()->retain_until?->toDateString())
        ->toBe(now()->addDays(730)->toDateString());
});

/*
 * FR-034 — the teacher gets a usable copy of their own content, and the route that
 * serves it is the one US3 already built: signed per press, expiring, allowlisted.
 */
it('opens a content export for the departing teacher', function (): void {
    Queue::fake();

    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/teaching/offboarding')->assertStatus(201);

    $this->getJson('/api/v1/teaching/offboarding/content')
        ->assertOk()
        ->assertJsonPath('data.type', 'export');
});

/*
 * ⚠️ FR-037 ENDS THE TEACHER'S ACCESS AND THEIR ASSISTANTS' — NOT THEIR STUDENTS'.
 *
 * The requirement names "their sessions, their access and their assistants'
 * permissions"; a student is none of those, and stripping their membership is a
 * side effect nobody asked for that lands one requirement away from the promise
 * that paid access survives.
 *
 * ⚠️ AND THIS CASE EXISTS BECAUSE THE OBVIOUS ONE DID NOT MEASURE IT. The
 * lesson-access test above stayed green with the exclusion deleted — the 403 it
 * originally caught came from a dropped workspace context, not from the role — so
 * the filter was a guard nothing watched. Asserted on the ROW, which is the thing
 * the listener deletes.
 */
it('leaves a student s membership of the departed workspace alone', function (): void {
    Queue::fake();

    [$course] = departingCourse((int) $this->workspace->getKey());

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Enrollment::create([
        'workspace_id' => $this->workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    $offboarding = app(RequestTeacherOffboarding::class)->handle($this->workspace, $this->teacher);

    TeacherOffboarding::query()
        ->whereKey($offboarding->getKey())
        ->update(['notice_ends_at' => now()->subDay()]);

    app(ExecuteTeacherOffboarding::class)->handle($offboarding->refresh(), $this->officer);

    expect(WorkspaceMember::query()
        ->where('workspace_id', $this->workspace->getKey())
        ->where('user_id', $student->getKey())
        ->count())->toBe(1)
        // …while the teacher's own membership is gone, which is what the
        // requirement actually asks for.
        ->and(WorkspaceMember::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('user_id', $this->teacher->getKey())
            ->count())->toBe(0);
});
