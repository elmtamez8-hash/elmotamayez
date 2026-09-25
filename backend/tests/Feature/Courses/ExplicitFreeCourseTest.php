<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Actions\Public\ReadPublicCourse;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| A course is free ONLY when its teacher ticks «كورس مجاني» (owner decision
| 2026-09-25).
|
| ⛔ The inferred rule — «price 0 and no sellable plan» — opened every NEW course
| for free once the price left the form: a course is sold through plans only,
| and a course born at price 0 with no plan yet read as free until its teacher
| made one. Nobody had decided to give it away.
|
| ⚠️ EACH DIRECTION IS MEASURED: an unflagged course is refused, a flagged one
| enrols, and the backfill keeps exactly today's free courses free.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    // A student is a member of no workspace, and nothing on their path stamps
    // `last_workspace_id`.
    $this->student = User::factory()->create(['last_workspace_id' => null]);
});

/** A course made the way a teacher makes one — through the API. */
function teacherMakesCourse(User $teacher, array $extra = []): Course
{
    Sanctum::actingAs($teacher);

    $uuid = test()->postJson('/api/v1/courses', [
        'title' => 'كورس جديد',
        'subject' => (string) Subject::factory()->create()->uuid,
        'course_type' => Course::TYPE_RECORDED,
        ...$extra,
    ])->assertCreated()->json('uuid');

    $course = Course::query()->withoutWorkspaceScope()->where('uuid', $uuid)->sole();
    $course->forceFill(['status' => 'published', 'visibility' => 'public'])->save();
    app()->forgetInstance(WorkspaceContext::class);

    return $course->refresh();
}

it('does not give away a new course that has no plan yet', function (): void {
    $course = teacherMakesCourse($this->teacher);

    expect($course->isFree())->toBeFalse()
        ->and((int) $course->price_minor)->toBe(0);

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/courses/'.$course->uuid.'/enroll')
        ->assertStatus(422)
        ->assertJsonPath('code', 'purchase_required');

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(0);

    // And the public page's `free_enrollment` asks the same helper — no
    // «سجّل مجاناً» over a door that refuses.
    expect(app(ReadPublicCourse::class)->freeEnrollment($course))->toBeFalse();
});

it('enrols anyone for free in a course its teacher marked free', function (): void {
    $course = teacherMakesCourse($this->teacher, ['is_free_enrollment' => true]);

    expect($course->isFree())->toBeTrue();

    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/courses/'.$course->uuid.'/enroll')
        ->assertCreated();

    expect(Enrollment::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('lets the teacher switch the flag on an existing course', function (): void {
    $course = teacherMakesCourse($this->teacher);

    Sanctum::actingAs($this->teacher);
    $this->putJson('/api/v1/courses/'.$course->uuid, ['is_free_enrollment' => true])
        ->assertOk()
        ->assertJsonPath('is_free_enrollment', true)
        ->assertJsonPath('is_free', true);

    expect($course->refresh()->isFree())->toBeTrue();
});

it('backfills the flag for exactly the courses that are free today', function (): void {
    $make = fn (array $attributes): Course => app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            ...$attributes,
        ]),
    );

    $freeToday = $make(['price_minor' => 0]);
    $soldByPlan = $make(['price_minor' => 0]);
    $priced = $make(['price_minor' => 4_999]);

    Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $soldByPlan->uuid,
    ]);

    // As the rows stand before the migration: nobody has ticked anything.
    DB::table('courses')->update(['is_free_enrollment' => false]);

    $migration = require base_path('app/Modules/Courses/Database/Migrations/2026_09_26_000100_add_is_free_enrollment_to_courses.php');
    $migration->backfill();

    expect($freeToday->refresh()->isFree())->toBeTrue()
        ->and($soldByPlan->refresh()->isFree())->toBeFalse()
        ->and($priced->refresh()->isFree())->toBeFalse();
});
