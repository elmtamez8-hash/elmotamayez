<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| «إضافة طالب» — the picker behind `POST /manage/cohorts/{cohort}/members`.
|
| ⚠️ THE PICKER IS THE AUTHORISER'S OWN PREDICATE, AND THE LAST CASE PROVES IT.
| `MoveMember` accepts a student with a GRANTING enrolment (`active` or
| `completed`) in the group's course. Every uuid this list offers is walked
| through the real write, so the day the two spellings drift apart — an option
| the door refuses, or a student the door would take and the list hides — this
| file fails rather than a teacher.
*/

beforeEach(function (): void {
    app()->forgetInstance(WorkspaceContext::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->other = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $make = fn (string $name): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'name' => $name,
        'capacity' => null,
    ]);

    $this->saturday = $make('مجموعة السبت');
    $this->sunday = $make('مجموعة الأحد');

    $this->enrol = function (string $first, string $status, ?Course $course = null): User {
        $student = User::factory()->create(['first_name' => $first, 'last_name' => 'ط']);

        Enrollment::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => ($course ?? $this->course)->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => $status,
            'enrolled_at' => now(),
        ]);

        return $student;
    };
});

function eligibleUrl(Cohort $cohort): string
{
    return "/api/v1/manage/cohorts/{$cohort->uuid}/eligible-students";
}

it('offers the course students the write accepts, and says which group each is in now', function (): void {
    $fresh = ($this->enrol)('سارة', 'active');
    $finished = ($this->enrol)('منى', 'completed');
    $moving = ($this->enrol)('علي', 'active');
    $already = ($this->enrol)('هدى', 'active');
    $lapsed = ($this->enrol)('خالد', 'cancelled');
    $elsewhere = ($this->enrol)('يوسف', 'active', $this->other);

    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->sunday->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $moving->getKey(),
        'joined_at' => now(),
    ]);

    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->saturday->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $already->getKey(),
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($this->owner);

    $rows = collect($this->getJson(eligibleUrl($this->saturday))->assertOk()->json('data'))->keyBy('uuid');

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$fresh->uuid, $finished->uuid, $moving->uuid])->sort()->values()->all())
        ->and($rows[$moving->uuid]['current_cohort']['name'])->toBe('مجموعة الأحد')
        ->and($rows[$fresh->uuid]['current_cohort'])->toBeNull()
        ->and($rows[$fresh->uuid]['name'])->toContain('سارة')
        ->and($rows->has($lapsed->uuid))->toBeFalse()
        ->and($rows->has($elsewhere->uuid))->toBeFalse();
});

it('refuses a member who may not manage the group', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Sanctum::actingAs($student);

    $this->getJson(eligibleUrl($this->saturday))->assertForbidden();
});

it('offers nobody the write would refuse', function (): void {
    ($this->enrol)('سارة', 'active');
    ($this->enrol)('منى', 'completed');
    $moving = ($this->enrol)('علي', 'active');

    CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $this->sunday->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $moving->getKey(),
        'joined_at' => now(),
    ]);

    Sanctum::actingAs($this->owner);

    $uuids = collect($this->getJson(eligibleUrl($this->saturday))->assertOk()->json('data'))->pluck('uuid');

    expect($uuids)->toHaveCount(3);

    foreach ($uuids as $uuid) {
        $this->postJson("/api/v1/manage/cohorts/{$this->saturday->uuid}/members", ['student_uuid' => $uuid])
            ->assertCreated();
    }

    // And once they are all in, the list is empty rather than a list of refusals.
    expect($this->getJson(eligibleUrl($this->saturday))->assertOk()->json('data'))->toBe([]);
});
