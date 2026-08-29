<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Support\Flags;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/*
| ⚠️ WHO DECIDES THE WORKSPACE FOR A READER WHO IS A STUDENT?
|
| A student is a member of no workspace at all — only `AcceptInvitation` and
| `CreateWorkspace` write that pivot — so `WorkspaceContext::id()` is NULL for
| them and a flag read from the context alone falls on the PLATFORM row. Which
| means: a feature lit for one teacher is invisible to that teacher's own
| students, and a feature switched off for them stays visible. Both halves of
| SC-014 would be true for the teacher and false for their whole audience.
|
| The rule that follows is written in `Flags::enabled()`'s signature: the caller
| passes the workspace, and a student-facing caller derives it from the COURSE or
| the ENROLMENT rather than from the context. This file is what fails if somebody
| «simplifies» that parameter away.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$this->other] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    app(Flags::class)->forget();

    FeatureFlag::factory()->create(['key' => 'feature.lesson_beta', 'enabled' => false]);
    FeatureFlag::factory()->create([
        'key' => 'feature.lesson_beta',
        'workspace_id' => $this->workspace->getKey(),
        'enabled' => true,
    ]);
});

it('shows the feature to a student of that teacher, whose own context is null', function (): void {
    $course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->create(['workspace_id' => $this->workspace->getKey()]),
    );

    /*
    | The student's own context. `WorkspaceContext` is an application-wide
    | singleton that CACHES its resolution, so `forget()` alone is not enough
    | inside a test process that has already resolved it for the fixtures — a
    | fresh instance is what production gives a request from somebody who belongs
    | to no workspace. Rebuilding it is the only way to measure the state a real
    | student is in; without this the assertion below reads whatever workspace
    | the fixtures happened to leave behind.
    */
    $this->addWorkspaceMember($this->other, Roles::STUDENT);

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    expect(app(WorkspaceContext::class)->id())->toBeNull();

    // Derived from the course, which is the workspace the student is looking at.
    $answer = app(Flags::class)->enabled('feature.lesson_beta', (int) $course->workspace_id);

    expect($answer)->toBeTrue();
});

it('falls on the platform row when the caller passes no workspace, which is the bug', function (): void {
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    /*
    | Kept as an assertion rather than a warning in a comment: this is what a
    | student-facing caller gets if it reads the context instead of the course,
    | and the number it returns is «off» for a feature that IS on for that
    | teacher. The behaviour is correct — the platform row is the right answer to
    | the question asked — which is exactly why the question has to be asked with
    | the workspace in it.
    */
    expect(app(Flags::class)->enabled('feature.lesson_beta', null))->toBeFalse();
});
