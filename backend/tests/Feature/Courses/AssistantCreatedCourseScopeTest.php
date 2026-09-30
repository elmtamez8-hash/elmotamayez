<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Events\CourseCreated;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| A CONFINED assistant who creates a course keeps it inside their scope (owner
| decision 2026-09-29) — and an UNCONFINED one is never given a row, because one
| row would confine them to that single course.
|
| ⚠️ The confined case reads the scope BEFORE creating, with no
| `forgetScopedInstances()` anywhere after it: the directory memoises per request
| and the test container is not flushed between calls, so the edit and the list
| below pass only if the listener busts the memo it wrote past.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'draft']);
    $this->far = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'draft']);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    $this->subject = Subject::factory()->create();
});

function confineCreatorTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    app()->forgetScopedInstances();
}

function createCourseAs(string $title): Course
{
    test()->postJson('/api/v1/courses', [
        'title' => $title,
        // No currency: an assistant who CHOOSES one is refused since 2026-09-30
        // (pricing is the teacher's call — `CoursePolicy::choosePricing()`).
        'price_minor' => 0,
        'subject' => (string) test()->subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ])->assertCreated();

    return Course::query()->withoutWorkspaceScope()->where('title', $title)->sole();
}

/** @return list<int> */
function scopedCourseIdsOf(AssistantAssignment $assignment): array
{
    return AssistantScope::query()
        ->where('assistant_assignment_id', $assignment->getKey())
        ->pluck('course_id')
        ->map(fn ($id): int => (int) $id)
        ->sort()
        ->values()
        ->all();
}

it('puts a confined assistant\'s new course into their scope, editable on the next request', function (): void {
    confineCreatorTo($this->near);

    Sanctum::actingAs($this->assistant);

    // Memoise the old scope first — the bust below is what this makes load-bearing.
    $before = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();
    expect($before)->toBe([$this->near->uuid]);

    $created = createCourseAs('كورس المساعد');

    expect(scopedCourseIdsOf($this->assignment))
        ->toEqualCanonicalizing([(int) $this->near->getKey(), (int) $created->getKey()]);

    $this->putJson("/api/v1/courses/{$created->uuid}", ['title' => 'كورس المساعد — معدَّل'])->assertOk();
    expect($created->refresh()->title)->toBe('كورس المساعد — معدَّل');

    $listed = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();
    expect($listed)->toEqualCanonicalizing([$this->near->uuid, $created->uuid])
        ->and($listed)->not->toContain($this->far->uuid);

    // The confinement itself is untouched.
    $this->putJson("/api/v1/courses/{$this->far->uuid}", ['title' => 'x'])->assertForbidden();
});

it('never gives an unconfined assistant a scope row', function (): void {
    Sanctum::actingAs($this->assistant);

    createCourseAs('كورس غير مقيَّد');

    expect(scopedCourseIdsOf($this->assignment))->toBe([]);

    $listed = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();
    expect($listed)->toContain($this->near->uuid, $this->far->uuid);
});

it('writes no scope row when the owner creates a course', function (): void {
    confineCreatorTo($this->near);

    Sanctum::actingAs($this->owner);

    createCourseAs('كورس المالك');

    expect(scopedCourseIdsOf($this->assignment))->toBe([(int) $this->near->getKey()])
        ->and(AssistantScope::query()->count())->toBe(1);
});

it('writes no scope row for a revoked assignment', function (): void {
    confineCreatorTo($this->near);
    $this->assignment->forceFill(['revoked_at' => now()])->save();
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant);

    // The role still grants `courses.create`; the withdrawn assignment confines nothing.
    $created = createCourseAs('بعد السحب');

    expect(scopedCourseIdsOf($this->assignment))->toBe([(int) $this->near->getKey()])
        ->and(AssistantScope::query()->where('course_id', $created->getKey())->exists())->toBeFalse();
});

it('touches only the scope of the workspace the course was created in', function (): void {
    confineCreatorTo($this->near);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();

    [$otherAssignment, $otherCourse] = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other, $otherOwner): array {
        $course = Course::factory()->create(['workspace_id' => $other->getKey(), 'status' => 'draft']);
        $assignment = AssistantAssignment::factory()->create([
            'workspace_id' => $other->getKey(),
            'assistant_user_id' => $this->assistant->getKey(),
            'invited_by_user_id' => $otherOwner->getKey(),
        ]);
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $assignment->getKey(),
            'course_id' => $course->getKey(),
        ]);

        return [$assignment, $course];
    });

    Sanctum::actingAs($this->assistant);

    $created = createCourseAs('في المساحة الأولى');

    expect((int) $created->workspace_id)->toBe((int) $this->workspace->getKey())
        ->and(scopedCourseIdsOf($this->assignment))
        ->toEqualCanonicalizing([(int) $this->near->getKey(), (int) $created->getKey()])
        ->and(scopedCourseIdsOf($otherAssignment))->toBe([(int) $otherCourse->getKey()]);
});

it('is idempotent when the event is announced twice', function (): void {
    confineCreatorTo($this->near);

    event(new CourseCreated((int) $this->far->getKey(), (int) $this->workspace->getKey(), (int) $this->assistant->getKey()));
    event(new CourseCreated((int) $this->far->getKey(), (int) $this->workspace->getKey(), (int) $this->assistant->getKey()));

    expect(scopedCourseIdsOf($this->assignment))
        ->toEqualCanonicalizing([(int) $this->near->getKey(), (int) $this->far->getKey()]);
});
