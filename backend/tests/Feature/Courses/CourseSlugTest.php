<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10, F11 — a teacher saved a RIVAL course's uuid as their
| own course's slug, and the public lookup (slug first) handed them its address.
*/

function listedCourse(string $slug): Course
{
    $workspace = marketplaceWorkspace('Academy '.$slug);
    $teacher = marketplaceTeacher($workspace);

    return app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'slug' => $slug,
        'created_by' => $teacher->user_id,
    ]));
}

it('refuses a uuid-shaped course slug', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['slug' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('answers a uuid key with that uuid\'s course, whatever slug another course holds', function (): void {
    $victim = listedCourse('victim-course');
    // A row that slipped past the request (written before the rule, or by a seeder).
    $squatter = listedCourse('squatter-course');
    $squatter->forceFill(['slug' => $victim->uuid])->save();

    $this->asGuest();

    expect($this->getJson("/api/v1/marketplace/courses/{$victim->uuid}")->assertOk()->json('data.uuid'))
        ->toBe($victim->uuid);
});

/*
| ⛔ QA 2026-10-29: «../../admin x» was stored as a slug, and that course's public
| page could never be reached. A slug carries no URL-breaking character.
*/
it('refuses a slug carrying a character that breaks the address', function (string $slug): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['slug' => $slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');

    // A plain one is still taken.
    $this->putJson("/api/v1/courses/{$course->uuid}", ['slug' => 'physics-3'])->assertOk();
})->with(['../../admin x', 'a/b', 'a?b', 'a#b', 'a b']);
