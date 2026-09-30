<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Policies\CoursePolicy;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| سعرُ الكورسِ و«كورس مجاني» قرارُ المدرّس — قرارُ المالك 2026-09-30، على
| سابقةِ الظهور (TeacherCourseVisibilityTest).
|
| ⛔ كان `courses.update` وحدَه يحرسُ `is_free_enrollment` و`price_minor`
| و`currency`، والمساعدُ يحملُه — فمساعدٌ يضعُ علامةَ «مجاني» يفتحُ كورساً
| مدفوعاً لكلِّ طالبٍ بلا دفع.
*/

it('refuses an assistant making a paid course free, and saves nothing', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey(), 'is_free_enrollment' => false]);
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['is_free_enrollment' => true, 'title' => 'عنوان المساعد'])
        ->assertForbidden()
        ->assertJsonPath('message', CoursePolicy::PRICING_REFUSAL);

    $fresh = $course->fresh();
    expect((bool) $fresh?->is_free_enrollment)->toBeFalse()
        ->and($fresh?->title)->not->toBe('عنوان المساعد');
});

it('refuses an assistant moving the price or the currency', function (array $payload): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'price_minor' => 10_000,
        'currency' => 'QAR',
    ]);
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    $this->putJson("/api/v1/courses/{$course->uuid}", $payload)->assertForbidden();

    $fresh = $course->fresh();
    expect((int) $fresh?->price_minor)->toBe(10_000)
        ->and($fresh?->currency)->toBe('QAR');
})->with([
    'price' => [['price_minor' => 500]],
    'currency' => [['currency' => 'EGP']],
]);

it('lets an assistant keep editing — the edit screen echoes the current price keys', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'price_minor' => 10_000,
        'currency' => 'QAR',
        'is_free_enrollment' => false,
    ]);
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    $this->putJson("/api/v1/courses/{$course->uuid}", [
        'title' => 'عنوان جديد',
        'currency' => 'QAR',
        'is_free_enrollment' => false,
        'price_minor' => 10_000,
    ])->assertOk();

    expect($course->fresh()?->title)->toBe('عنوان جديد');
});

it('lets the owner and a teacher member price the course and make it free', function (string $who): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'is_free_enrollment' => false]);
    $actor = $who === 'owner' ? $owner : $this->addWorkspaceMember($workspace, Roles::TEACHER);

    Sanctum::actingAs($actor);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['is_free_enrollment' => true, 'price_minor' => 700])
        ->assertOk();

    $fresh = $course->fresh();
    expect((bool) $fresh?->is_free_enrollment)->toBeTrue()
        ->and((int) $fresh?->price_minor)->toBe(700);
})->with(['owner', 'teacher']);

it('refuses an assistant creating a course that is free, priced or in another currency', function (array $pricing): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $subject = Subject::factory()->create();
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/courses', array_merge([
        'title' => 'كورس المساعد',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ], $pricing))->assertForbidden()->assertJsonPath('message', CoursePolicy::PRICING_REFUSAL);

    expect(Course::query()->where('title', 'كورس المساعد')->exists())->toBeFalse();
})->with([
    'free' => [['is_free_enrollment' => true]],
    'priced' => [['price_minor' => 900]],
    'currency' => [['currency' => 'EGP']],
]);

it('lets an assistant create a course at the defaults — the platform currency included', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $subject = Subject::factory()->create();
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    // What «كورس جديد» posts: the platform currency, price zero, not free.
    $this->postJson('/api/v1/courses', [
        'title' => 'كورس المساعد',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
        'currency' => 'QAR',
        'price_minor' => 0,
        'is_free_enrollment' => false,
    ])->assertCreated();
});

it('lets the owner create a free course', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();
    $subject = Subject::factory()->create();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/courses', [
        'title' => 'كورس مجاني',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
        'is_free_enrollment' => true,
    ])->assertCreated()->assertJsonPath('is_free_enrollment', true);
});

it('tells the edit screen who may price — true for the owner, false for an assistant', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/courses/{$course->uuid}")->assertOk()->assertJsonPath('can_change_pricing', true);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($assistant);
    $this->getJson("/api/v1/courses/{$course->uuid}")->assertOk()->assertJsonPath('can_change_pricing', false);
});

it('tells the create screen whether to offer «كورس مجاني»', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_choose_course_pricing', true);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($assistant);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_choose_course_pricing', false);
});
