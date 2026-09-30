<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\RequestTransfer;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ «مَن مدرّسُ هذا الكورس؟» — مَلفُّه، لا مَن ضغطَ «أنشئ» (2026-09-30).
|
| `courses.created_by` هو من أنشأ الكورس، فإن كانَ **مساعداً** صارَ المساعدُ هو
| المدرّسَ في كلِّ مكان: طلباتُ الحصصِ الخاصّةِ وطلباتُ النقلِ بينَ المجموعاتِ
| تذهبُ إليه، والشهادةُ تطبعُ اسمَه، وبطاقةُ السوقِ تعرضُه، والسوقُ يحكمُ على
| ملفِّه هو (فيُخفي كورساً صحيحاً). المدرّسُ هو صاحبُ `teacher_profile_id`.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->workspace->forceFill(['participates_in_marketplace' => true])->save();

    $this->owner->forceFill(['first_name' => 'منى', 'last_name' => 'المدرّسة'])->save();

    $this->profile = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): TeacherProfile => TeacherProfile::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->owner->getKey(),
        ]),
    );

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->forceFill(['first_name' => 'سامي', 'last_name' => 'المساعد'])->save();
});

/** A course the ASSISTANT created, through the product's own door. */
function assistantBuiltCourse(): Course
{
    $subject = Subject::factory()->create();

    Sanctum::actingAs(test()->assistant);

    $uuid = test()->postJson('/api/v1/courses', [
        'title' => 'كورس بناه المساعد',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_GROUP,
    ])->assertCreated()->json('uuid');

    $course = Course::query()->withoutWorkspaceScope()->where('uuid', $uuid)->sole();

    // The published state is the owner's own later act; written directly here
    // because this file is about who the TEACHER is, not about publishing.
    $course->forceFill(['status' => 'published', 'visibility' => 'public'])->save();

    return $course;
}

it('records the assistant as the creator and the owner\'s profile as the teacher', function (): void {
    $course = assistantBuiltCourse();

    expect((int) $course->created_by)->toBe($this->assistant->getKey())
        ->and((int) $course->teacher_profile_id)->toBe($this->profile->getKey())
        ->and($course->fresh()?->teacherUser()?->getKey())->toBe($this->owner->getKey());
});

it('lists the assistant-built course in the marketplace under the TEACHER, not the assistant', function (): void {
    $course = assistantBuiltCourse();
    $this->asGuest();

    $card = collect($this->getJson('/api/v1/marketplace/courses')->assertOk()->json('data'))
        ->firstWhere('uuid', (string) $course->uuid);

    expect($card)->not->toBeNull()
        ->and($card['teacher']['name'])->toBe($this->owner->name)
        ->and($card['teacher']['uuid'])->toBe((string) $this->profile->uuid);

    $detail = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")->assertOk();

    expect($detail->json('data.teacher.name'))->toBe($this->owner->name)
        ->and((string) $detail->json('data.contact.name'))->toContain($this->owner->name)
        ->and((string) $detail->json('data.contact.name'))->not->toContain($this->assistant->name);

    // And on the teacher's own page, which filtered by `created_by` alone.
    $this->getJson("/api/v1/marketplace/courses?teacher={$this->profile->uuid}")
        ->assertOk()
        ->assertJsonFragment(['uuid' => (string) $course->uuid]);
});

it('tells the teacher\'s own screen the course IS reachable — the blockers judge the teacher\'s profile', function (): void {
    $course = assistantBuiltCourse();

    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('public_listing.listed', true)
        ->assertJsonPath('public_listing.blockers', []);
});

it('names the TEACHER on the student\'s «راسِل» and on the certificate', function (): void {
    $course = assistantBuiltCourse();
    $student = User::factory()->create();

    $enrollment = Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    Sanctum::actingAs($student);

    $row = collect($this->getJson('/api/v1/enrollments')->assertOk()->json('data'))
        ->firstWhere('course.uuid', (string) $course->uuid)
        ?? collect($this->getJson('/api/v1/enrollments')->json('data'))->first();

    expect((string) $row['contact_name'])->toContain($this->owner->name)
        ->and((string) $row['contact_name'])->not->toContain($this->assistant->name);

    $certificate = app(IssueCertificate::class)->handle($enrollment->fresh(), 'course_completed');

    expect($certificate->teacher_display_name)->toBe($this->owner->name);
});

it('shows the teacher, not the assistant, on the team screen\'s course picker', function (): void {
    $course = assistantBuiltCourse();

    Sanctum::actingAs($this->owner);

    $picked = collect($this->getJson('/api/v1/manage/assistants/courses')->assertOk()->json('data'))
        ->firstWhere('uuid', (string) $course->uuid);

    expect($picked['teacher']['name'])->toBe($this->owner->name);
});

it('asks the TEACHER about a group transfer on a course an assistant created', function (): void {
    $fx = cohortFixture();
    $profile = app(WorkspaceContext::class)->forWorkspace(
        $fx['workspace'],
        fn (): TeacherProfile => TeacherProfile::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'user_id' => $fx['owner']->getKey(),
        ]),
    );
    $assistant = $this->addWorkspaceMember($fx['workspace'], Roles::ASSISTANT_TEACHER);

    $fx['course']->forceFill([
        'created_by' => $assistant->getKey(),
        'teacher_profile_id' => $profile->getKey(),
    ])->save();

    $this->asGuest();

    app(JoinCohort::class)->handle($fx['a'], $fx['student']);
    app(RequestTransfer::class)->handle($fx['b'], $fx['student']);

    assertNotifiedOnce($fx['owner'], NotificationType::CohortTransferRequested);

    expect(wasNotified($assistant, NotificationType::CohortTransferRequested))->toBeFalse();
});

it('asks the TEACHER about a private session on a course an assistant created', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();
    $assistant = $this->addWorkspaceMember($fx['workspace'], Roles::ASSISTANT_TEACHER);

    $fx['course']->forceFill(['created_by' => $assistant->getKey()])->save();

    Sanctum::actingAs($fx['student']);

    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->pending()->count())->toBe(1);

    // The profile's person — the fixture is an academy's shape, so not the owner either.
    assertNotifiedOnce($fx['profile']->user()->firstOrFail(), NotificationType::PrivateSessionRequested);

    expect(wasNotified($assistant, NotificationType::PrivateSessionRequested))->toBeFalse();
});

it('still answers with the creator for a course that has no profile recorded — the legacy truth', function (): void {
    $course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'teacher_profile_id' => null,
    ]);

    expect($course->fresh()?->teacherUser()?->getKey())->toBe($this->owner->getKey());
});
