<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| A public page is public for people who are SIGNED IN too.
|
| ⚠️ THE WHOLE SUITE WAS BLIND TO THIS UNTIL 2026-09-03, and the reason is worth
| keeping: every public read is asserted as a GUEST, and to a guest
| `WorkspaceScope` adds no condition at all — so the correct answer and the
| broken one agree on exactly the case everybody tests.
|
| Signed in, the context resolves through `users.last_workspace_id`, the global
| scope ANDs the reader's own workspace onto a cross-tenant query, and a teacher
| browsing the marketplace was answered 404 about every course and every profile
| that was not their own — while the listings quietly shrank to their own rows.
|
| Every test here therefore uses TWO workspaces and an authenticated reader from
| the wrong one. One workspace cannot see this defect: the scope ANDs the right
| id by accident.
*/

it('opens another workspace course to a signed-in reader', function (): void {
    $host = marketplaceWorkspace('Host Academy');
    $hostTeacher = marketplaceTeacher($host);

    $course = app(WorkspaceContext::class)->forWorkspace($host, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $host->getKey(),
            'title' => 'أساسيّات التفاضل',
            'created_by' => $hostTeacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    // A reader whose own workspace is a different one entirely.
    $elsewhere = marketplaceWorkspace('Another Academy');
    $reader = $this->addWorkspaceMember($elsewhere, Roles::TEACHER);
    Sanctum::actingAs($reader);

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.title', 'أساسيّات التفاضل');
});

it('opens another workspace teacher profile to a signed-in reader', function (): void {
    $host = marketplaceWorkspace('Host Academy');
    $hostTeacher = marketplaceTeacher($host);

    $elsewhere = marketplaceWorkspace('Another Academy');
    $reader = $this->addWorkspaceMember($elsewhere, Roles::TEACHER);
    Sanctum::actingAs($reader);

    $this->getJson('/api/v1/marketplace/teachers/'.($hostTeacher->slug ?? $hostTeacher->uuid))
        ->assertOk();
});

it('lists every workspace course to a signed-in reader, not just their own', function (): void {
    $host = marketplaceWorkspace('Host Academy');
    $hostTeacher = marketplaceTeacher($host);

    app(WorkspaceContext::class)->forWorkspace($host, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $host->getKey(),
            'created_by' => $hostTeacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    $elsewhere = marketplaceWorkspace('Another Academy');
    $reader = $this->addWorkspaceMember($elsewhere, Roles::TEACHER);
    Sanctum::actingAs($reader);

    /*
    | ⚠️ THE LISTING IS THE HALF NOBODY WOULD HAVE NOTICED. A 404 on a detail
    | page is loud; a browse page that silently returns fewer rows looks like an
    | empty marketplace, and nothing anywhere reports it.
    */
    $this->getJson('/api/v1/marketplace/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/*
| And the guard itself has not moved: dropping the tenant scope must not publish
| anything the three conditions refuse. This is the assertion that would fail if
| somebody "simplified" the bypass into a wider one.
*/
it('still refuses a draft course to a signed-in reader from another workspace', function (): void {
    $host = marketplaceWorkspace('Host Academy');
    $hostTeacher = marketplaceTeacher($host);

    $draft = app(WorkspaceContext::class)->forWorkspace($host, fn (): Course => Course::factory()
        ->create([
            'workspace_id' => $host->getKey(),
            'status' => 'draft',
            'created_by' => $hostTeacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    $elsewhere = marketplaceWorkspace('Another Academy');
    Sanctum::actingAs($this->addWorkspaceMember($elsewhere, Roles::TEACHER));

    $this->getJson("/api/v1/marketplace/courses/{$draft->uuid}")->assertNotFound();
});

it('still refuses a course whose workspace left the marketplace', function (): void {
    $host = marketplaceWorkspace('Host Academy', participates: false);
    $hostTeacher = marketplaceTeacher($host);

    $course = app(WorkspaceContext::class)->forWorkspace($host, fn (): Course => Course::factory()
        ->published()
        ->create([
            'workspace_id' => $host->getKey(),
            'created_by' => $hostTeacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]));

    $elsewhere = marketplaceWorkspace('Another Academy');
    Sanctum::actingAs($this->addWorkspaceMember($elsewhere, Roles::TEACHER));

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")->assertNotFound();
});
