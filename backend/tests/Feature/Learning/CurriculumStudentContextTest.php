<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| The course page, opened by the student production actually has.
|
| ⚠️ NOTHING ON A STUDENT'S PATH WRITES `users.last_workspace_id`. Enrolling
| writes nothing and signing in writes nothing; its only writers are
| `CreateWorkspace`, `WorkspaceContext::set()` — reached from `AcceptInvitation`
| and `SwitchWorkspace`, both about workspace MEMBERS — and the two seeders. So
| for every real student the context resolves to NULL, `WorkspaceScope::apply()`
| adds no condition at all, and there is no spatie team id, which makes every
| `can()` below it false.
|
| Two consequences, and this file exists for both. The scope protects NOTHING
| here, so an explicit ownership lookup is the entire guard — and a test whose
| student was built with `addWorkspaceMember()` or came out of a seeder is
| measuring a person who does not exist. That fixture defect hid FIVE dead
| endpoints for a whole phase, including the only lesson player in the product,
| and every test involved was green throughout.
*/

it('opens the curriculum for a student who belongs to no workspace at all', function (): void {
    $tree = $this->curriculumTree();

    // The whole point of the fixture. If this ever passes with a value in it,
    // every assertion below is about somebody production never creates.
    expect($tree['student']->refresh()->last_workspace_id)->toBeNull();

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    expect(app(WorkspaceContext::class)->id())->toBeNull();

    $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')
        ->assertOk()
        ->assertJsonPath('course.uuid', $tree['course']->uuid);
});

it('refuses a signed-in stranger, whose context is null in exactly the same way', function (): void {
    /*
     | The control, and it is not redundant. The case above would also pass
     | against an endpoint that had simply stopped checking anything — and a null
     | context is precisely the state in which "no condition applied" and "allowed"
     | look identical from the outside.
     */
    $tree = $this->curriculumTree();

    Sanctum::actingAs(User::factory()->create());
    $this->forgetWorkspace();

    $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertForbidden();
});

it('refuses another teacher\'s student, who is enrolled somewhere else entirely', function (): void {
    // The direction the ownership lookup exists for: a real student with a real
    // enrolment, in a different course. `WorkspaceScope` cannot tell these two
    // apart, because it is not running.
    $mine = $this->curriculumTree();
    $theirs = $this->curriculumTree();

    Sanctum::actingAs($theirs['student']);
    $this->forgetWorkspace();

    $this->getJson('/api/v1/courses/'.$mine['course']->uuid.'/curriculum')->assertForbidden();

    // And their own course still opens, so the refusal above is about the course
    // rather than about the reader.
    $this->getJson('/api/v1/courses/'.$theirs['course']->uuid.'/curriculum')->assertOk();
});

it('refuses the teacher, who holds no enrolment in their own workspace', function (): void {
    /*
     | ⚠️ A REFUSAL, AND A DELIBERATE ONE. A teacher has no enrolment in their own
     | course — the pivot is written only by `AcceptInvitation` and
     | `CreateWorkspace` — so this endpoint is not theirs, and widening it to
     | "workspace member" would hand the author a student's progress bar and a
     | «تابعْ من هنا» button about their own material. The teacher's view of the
     | tree is `/manage/courses/{uuid}/content`, which already exists.
    */
    $tree = $this->curriculumTree();

    Sanctum::actingAs($tree['owner']);
    $this->forgetWorkspace();

    $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertForbidden();
});
