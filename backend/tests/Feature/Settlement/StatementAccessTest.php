<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| Who may read a statement, and whose.
|
| Two different refusals live here and they are not the same rule:
|
|   SC-010 — the assistant teacher runs the classroom and holds every session
|   permission there is. Reading the teacher's rate is reading their CONTRACT,
|   which is a different question with a different answer (FR-020).
|
|   SC-011 — one teacher never sees another's. The workspace scope is not enough
|   for that on its own: a workspace can hold several teacher profiles, and the
|   seeded demo academy holds six. The filter is on `user_id`, so the answer is
|   the reader's own by construction rather than by a check somebody maintains.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);

    TeachingUnit::factory()->count(3)->create(['teacher_profile_id' => $this->teacher->getKey()]);
});

it('shows the teacher their own statement', function (): void {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/settlement/statement')
        ->assertOk()
        ->assertJsonPath('students_count', 3)
        ->assertJsonPath('units.accrued', 3);
});

it('refuses the assistant teacher the statement and the export', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    Sanctum::actingAs($assistant);

    $this->getJson('/api/v1/settlement/statement')->assertForbidden();
    $this->get('/api/v1/settlement/statement/export')->assertForbidden();
});

it('refuses a student the statement and the export', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/settlement/statement')->assertForbidden();
    $this->get('/api/v1/settlement/statement/export')->assertForbidden();
});

it('shows a second teacher in the same workspace nothing of the first', function (): void {
    // The case the workspace scope cannot catch, because both profiles are in the
    // same workspace. Two teachers under one academy is the normal arrangement,
    // not an edge case.
    $other = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $otherProfile = TeacherProfile::factory()->create(['user_id' => $other->getKey()]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $otherProfile->getKey(),
        'amount_minor' => 12_000,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);

    Sanctum::actingAs($other);

    $statement = $this->getJson('/api/v1/settlement/statement')->assertOk();

    // Their own empty statement, not their colleague's three units.
    expect($statement->json('units.accrued'))->toBe(0)
        ->and($statement->json('students_count'))->toBe(0)
        ->and($statement->json('gross_minor'))->toBe(0)
        // And their own price, at their own number.
        ->and($statement->json('rates.0.amount_minor'))->toBe(12_000);

    $this->getJson('/api/v1/settlement/units')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->getJson('/api/v1/settlement/rates')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.amount_minor', 12_000);
});

it('has no parameter that could name another teacher', function (): void {
    $other = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $otherProfile = TeacherProfile::factory()->create(['user_id' => $other->getKey()]);

    Sanctum::actingAs($other);

    // Passing the first teacher's uuid every way a route could take one. The
    // parameter does not exist, so it cannot be honoured — which is the point of
    // resolving the profile from the token instead (FR-019).
    $this->getJson("/api/v1/settlement/statement?teacher={$this->teacher->uuid}")
        ->assertOk()
        ->assertJsonPath('units.accrued', 0);

    $this->getJson("/api/v1/settlement/units?teacher_profile_id={$this->teacher->getKey()}")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    expect($otherProfile->fresh())->not->toBeNull();
});

it('answers 404 rather than a colleague\'s statement for a reader with no profile', function (): void {
    // A workspace owner who is not themselves a teacher. Falling back to "the
    // teacher in this workspace" would hand them a colleague's contract.
    [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية بلا ملف']);
    $this->setCurrentWorkspace($workspace, $owner);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/settlement/statement')->assertNotFound();
});
