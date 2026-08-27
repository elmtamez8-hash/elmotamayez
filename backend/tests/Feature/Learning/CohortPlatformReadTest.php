<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| ⚠️ EVERY CROSS-WORKSPACE ASSERTION IN THIS PHASE NEEDS TWO WORKSPACES, AND ONE
| IS WHY THIS KEEPS SHIPPING BROKEN.
|
| `WorkspaceContext::id()` falls back to `users.last_workspace_id` for every user
| including platform staff, so a fixture with a single workspace has the reader's
| fallback and the data's owner agreeing by accident. `ExecuteTeacherOffboarding`
| went out with all three of its conditional UPDATEs scoped to the wrong
| workspace and every test green, and the audit chain answered «nothing was
| bought» with a `200` for the same reason.
*/

/** @return array{workspace: mixed, owner: mixed, cohort: Cohort} */
function cohortInItsOwnWorkspace(string $name): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner(['name' => $name]);

    $cohort = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $name): Cohort {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]);

        return Cohort::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'name' => $name.' — السبت',
        ]);
    });

    return ['workspace' => $workspace, 'owner' => $owner, 'cohort' => $cohort];
}

it('shows a teacher their own groups and not the other academy\'s', function (): void {
    $mine = cohortInItsOwnWorkspace('Academy A');
    $theirs = cohortInItsOwnWorkspace('Academy B');

    Sanctum::actingAs($mine['owner']);
    $this->setCurrentWorkspace($mine['workspace'], $mine['owner']);

    $names = collect($this->getJson('/api/v1/manage/courses/'.$mine['cohort']->course->uuid.'/cohorts')
        ->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe([$mine['cohort']->name]);

    // ⚠️ AND THE ROW-LEVEL DOOR IS ASKED SEPARATELY. A list filtered by a query
    // and a record fetched by id are two different questions — 010 shipped a
    // careful `view()` beside a table that never called it.
    $this->patchJson('/api/v1/manage/cohorts/'.$theirs['cohort']->uuid, ['name' => 'مسروقة'])
        ->assertNotFound();

    expect($theirs['cohort']->refresh()->name)->toBe('Academy B — السبت');
});

it('keeps one academy\'s history out of another\'s', function (): void {
    $mine = cohortInItsOwnWorkspace('Academy A');
    $theirs = cohortInItsOwnWorkspace('Academy B');

    app(WorkspaceContext::class)->forWorkspace($theirs['workspace'], fn () => CohortMembershipEvent::factory()->create([
        'workspace_id' => $theirs['workspace']->getKey(),
        'course_id' => $theirs['cohort']->course_id,
        'cohort_id' => $theirs['cohort']->getKey(),
        'actor_user_id' => $theirs['owner']->getKey(),
    ]));

    Sanctum::actingAs($mine['owner']);
    $this->setCurrentWorkspace($mine['workspace'], $mine['owner']);

    $this->getJson('/api/v1/manage/cohorts/'.$theirs['cohort']->uuid.'/history')->assertNotFound();

    // Their own history resolves, so the assertion above is about the WORKSPACE
    // and not about a route that simply never answers.
    $this->getJson('/api/v1/manage/cohorts/'.$mine['cohort']->uuid.'/history')->assertOk();
});

it('refuses a student the teacher routes entirely', function (): void {
    $fx = cohortFixture();

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $this->getJson('/api/v1/manage/courses/'.$fx['course']->uuid.'/cohorts')->assertForbidden();

    $this->postJson('/api/v1/manage/courses/'.$fx['course']->uuid.'/cohorts', [
        'name' => 'مجموعتي',
    ])->assertForbidden();
});
