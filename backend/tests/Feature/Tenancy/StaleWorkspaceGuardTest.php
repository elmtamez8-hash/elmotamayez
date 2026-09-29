<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Middleware\RefuseStaleWorkspace;
use Laravel\Sanctum\Sanctum;

/*
| The current workspace is per ACCOUNT, never per tab: `POST /workspaces/{uuid}/switch`
| rewrites `users.last_workspace_id`, and a second tab still drawing workspace A
| then read and WROTE workspace B. The page names what it is showing in
| `X-Workspace`; a mismatch is 409 `workspace_changed` before anything runs.
|
| ⚠️ TWO WORKSPACES, and the switch happens «in another tab» — simulated by
| moving the column and dropping the context singleton, which is exactly the
| state the second tab's next request meets on the server.
*/

/**
 * A teacher who owns A and teaches at B, currently in B — as if another tab had
 * just switched. Returns [A, B, teacher, subject].
 *
 * @return array{0: Workspace, 1: Workspace, 2: User, 3: Subject}
 */
function teacherSwitchedElsewhere(object $test): array
{
    // The WithWorkspace helpers are protected; `call()` borrows the test's scope.
    return (function (): array {
        [$a, $teacher] = $this->createWorkspaceWithOwner(['name' => 'مكان أ']);
        [$b] = $this->createWorkspaceWithOwner(['name' => 'مكان ب']);

        $this->addWorkspaceMember($b, 'teacher', $teacher);

        $subject = Subject::factory()->create();

        // «Another tab» switched to B. Fresh singleton: the next request resolves
        // from the column, as a real one does.
        $teacher->forceFill(['last_workspace_id' => $b->getKey()])->save();
        $this->asGuest();

        Sanctum::actingAs($teacher->fresh());

        return [$a, $b, $teacher, $subject];
    })->call($test);
}

/** @return array<string, mixed> */
function staleGuardCoursePayload(Subject $subject, string $title): array
{
    return [
        'title' => $title,
        'description' => 'x',
        'price_minor' => 1000,
        'currency' => 'EGP',
        'is_sequential' => false,
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ];
}

it('refuses a write from a tab still showing the old workspace, and writes nothing', function (): void {
    [$a, $b, , $subject] = teacherSwitchedElsewhere($this);

    $before = Course::query()->withoutWorkspaceScope()->count();

    $this->postJson('/api/v1/courses', staleGuardCoursePayload($subject, 'من التبويب القديم'), [
        RefuseStaleWorkspace::HEADER => (string) $a->uuid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'workspace_changed');

    expect(Course::query()->withoutWorkspaceScope()->count())->toBe($before)
        ->and(Course::query()->withoutWorkspaceScope()->where('title', 'من التبويب القديم')->exists())->toBeFalse();
});

it('answers 409, not a scoped 404, for a stale read of the old workspace\'s row', function (): void {
    [$a, , $teacher] = teacherSwitchedElsewhere($this);

    $course = Course::factory()->create(['workspace_id' => $a->getKey()]);

    // Without the header the scope (context B) hides A's course — the 404 the
    // stale tab would have been shown, which is why the guard runs before binding.
    $this->getJson("/api/v1/courses/{$course->uuid}")->assertNotFound();

    $this->getJson("/api/v1/courses/{$course->uuid}", [RefuseStaleWorkspace::HEADER => (string) $a->uuid])
        ->assertStatus(409)
        ->assertJsonPath('code', 'workspace_changed');
});

it('lets a request naming the resolved workspace through', function (): void {
    [, $b, , $subject] = teacherSwitchedElsewhere($this);

    $this->postJson('/api/v1/courses', staleGuardCoursePayload($subject, 'في المكان الصحيح'), [
        RefuseStaleWorkspace::HEADER => (string) $b->uuid,
    ])->assertCreated();

    $created = Course::query()->withoutWorkspaceScope()->where('title', 'في المكان الصحيح')->sole();

    expect((int) $created->workspace_id)->toBe((int) $b->getKey());
});

it('checks nothing when no header is sent', function (): void {
    [, $b, , $subject] = teacherSwitchedElsewhere($this);

    $this->postJson('/api/v1/courses', staleGuardCoursePayload($subject, 'بلا ترويسة'))
        ->assertCreated();

    expect((int) Course::query()->withoutWorkspaceScope()->where('title', 'بلا ترويسة')->sole()->workspace_id)
        ->toBe((int) $b->getKey());
});

it('tells the page which workspace it is in, from the resolved context', function (): void {
    [, $b] = teacherSwitchedElsewhere($this);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('current_workspace.uuid', (string) $b->uuid)
        ->assertJsonPath('current_workspace.name', 'مكان ب');
});

it('lets a stale tab switch and sign out — both are exempt', function (): void {
    [$a, $b] = teacherSwitchedElsewhere($this);

    // The stale tab still believes B after pressing «انتقل إليه» on A elsewhere…
    $this->postJson("/api/v1/workspaces/{$a->uuid}/switch", [], [RefuseStaleWorkspace::HEADER => 'not-this-one'])
        ->assertOk();

    $this->postJson('/api/v1/auth/logout', [], [RefuseStaleWorkspace::HEADER => (string) $b->uuid])
        ->assertSuccessful();
});

it('refuses a claim when the account resolves no workspace at all', function (): void {
    [$a, , $teacher] = teacherSwitchedElsewhere($this);

    // Removed from every place in another tab: the scope would be inert.
    $teacher->forceFill(['last_workspace_id' => null])->save();
    $this->asGuest();
    Sanctum::actingAs($teacher->fresh());

    $this->getJson('/api/v1/courses', [RefuseStaleWorkspace::HEADER => (string) $a->uuid])
        ->assertStatus(409)
        ->assertJsonPath('code', 'workspace_changed');
});

it('sends no current workspace to a student who is a member of nothing', function (): void {
    $student = User::factory()->create();

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('current_workspace', null);
});
