<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| SC-011 — خيطُ المجموعةِ لأعضائها.
|
| ⚠️ والرفضُ يُقاسُ في البابَين: القائمةُ **والطلبُ المباشرُ بالمعرّف**. مرشِّحٌ على
| القائمةِ وحدَه يُخفي الخيطَ عن شاشةٍ ويتركُه مفتوحاً لمن يعرفُ المعرّف — وهو
| بالضبطِ العيبُ الذي سقطتْ فيه `/exams` و`/assignments` في ٠٠٨.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();
});

/** A second real student, enrolled in the same course and in the OTHER group. */
function otherGroupStudent(object $test): User
{
    $fx = $test->fx;

    // ⚠️ NO `addWorkspaceMember()`. That helper stamps `last_workspace_id` and
    // attaches a pivot row, which gives the fixture a person production never
    // creates — and a workspace member is admitted to `publicRoom()` by its very
    // first branch, so the isolation this file measures would never be reached.
    $student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $student): void {
        Enrollment::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    app(JoinCohort::class)->handle($fx['b'], $student);

    return $student;
}

it('refuses the other group thread to a member of this one, by uuid', function (): void {
    $outsider = otherGroupStudent($this);

    Sanctum::actingAs($this->fx['student']);
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    Sanctum::actingAs($this->fx['student']);

    // ⚠️ THE DIRECT REQUEST BY IDENTIFIER, which is the door a list filter does
    // not close. `/cohorts/{uuid}/chat` resolves-or-creates, so the refusal has
    // to come from the policy on an UNSAVED row as well as on a saved one.
    $this->getJson('/api/v1/cohorts/'.$this->fx['b']->uuid.'/chat')->assertForbidden();

    expect($outsider)->not->toBeNull();
});

/*
| ⚠️ AND THE MESSAGES THEMSELVES, not only the thread. A reader refused the room
| and then handed its messages by a second endpoint has been refused nothing.
*/
it('refuses the other group messages to a member of this one', function (): void {
    $outsider = otherGroupStudent($this);

    Sanctum::actingAs($outsider);
    $roomB = $this->getJson('/api/v1/cohorts/'.$this->fx['b']->uuid.'/chat')->assertOk()->json();

    $this->postJson('/api/v1/conversations/'.$roomB['uuid'].'/messages', [
        'body' => 'DRAFT_SENTINEL — كلام مجموعة الأحد',
    ])->assertCreated();

    $this->asGuest();
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    Sanctum::actingAs($this->fx['student']);

    $response = $this->getJson('/api/v1/conversations/'.$roomB['uuid'].'/messages')->assertForbidden();

    /*
    | ⚠️ AN ASCII SENTINEL, ON PURPOSE. `getContent()` escapes everything
    | non-ASCII, so an Arabic needle asserted against the raw body is vacuously
    | absent whatever the payload holds — every exposure test in this product
    | would pass against a response that leaked the lot.
    */
    expect((string) $response->getContent())->not->toContain('DRAFT_SENTINEL');
});

/*
| ⚠️ THE STUDENT WHO MOVED KEEPS READING THE OLD THREAD — FR-046, and the one
| behaviour that makes this feature's two doors different from every other room's.
| The thread is where their teacher explained something; taking the archive away
| on the day they change their Saturday is the FR-014 defect in a second shape.
*/
it('keeps the old thread readable after a transfer and closes it to writing', function (): void {
    Sanctum::actingAs($this->fx['student']);
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    Sanctum::actingAs($this->fx['student']);
    $roomA = $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/chat')->assertOk()->json();

    $this->postJson('/api/v1/conversations/'.$roomA['uuid'].'/messages', [
        'body' => 'شكراً على الشرح.',
    ])->assertCreated();

    $this->asGuest();
    app(MoveMember::class)->handle($this->fx['b'], $this->fx['student'], $this->fx['owner']);

    Sanctum::actingAs($this->fx['student']);

    // Reading: still theirs.
    $this->getJson('/api/v1/conversations/'.$roomA['uuid'].'/messages')->assertOk();

    // Writing: not any more. Two doors, two answers.
    $this->postJson('/api/v1/conversations/'.$roomA['uuid'].'/messages', [
        'body' => 'سؤال جديد.',
    ])->assertForbidden();
});

/*
| ⚠️ THE TEACHER HOLDS NO MEMBERSHIP IN THEIR OWN GROUP, and reading the write
| door as «is a current member» without the moderator exemption would lock them
| out of every thread they run — the lock's own lesson, one requirement later.
*/
it('lets the teacher write in a group they are not a member of', function (): void {
    $this->asGuest();
    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);

    Sanctum::actingAs($this->fx['student']);
    $room = $this->getJson('/api/v1/cohorts/'.$this->fx['a']->uuid.'/chat')->assertOk()->json();

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    Sanctum::actingAs($this->fx['owner']);

    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/messages', [
        'body' => 'أهلاً بالجميع.',
    ])->assertCreated();
});
