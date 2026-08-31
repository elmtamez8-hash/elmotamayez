<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Flags;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · spec 011's switch. On per teacher, and off is not an error.
|
| ⚠️ EVERY CASE HERE USES A STUDENT WITH `users.last_workspace_id` NULL AND A
| RESET CONTEXT, because that is the only person this feature is for — and it is
| the person the convenient fixtures do not create. `addWorkspaceMember()` stamps
| that column and `setCurrentWorkspace()` sets a context the product never gives
| a student, so a flag test built either way would be measuring somebody else.
*/

it('refuses a start when the teacher has the switch off, with a code the screen can read', function (): void {
    $fx = adaptiveFixture(['easy', 'easy'], enabled: false);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $response = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertStatus(403);

    // ⚠️ A CODE, NOT A SENTENCE. The client greys the card and explains; matching
    // on Arabic prose breaks the first time somebody improves the wording.
    expect($response->json('code'))->toBe('feature_off');
});

/*
| ⚠️ AN EMPTY LIST, NEVER A 403, AND THE TWO ARE DIFFERENT SCREENS. The list takes
| no `teacher` parameter, so «is the switch on» has one answer per teacher — a
| refusal would be the API deciding a page is an error when it is simply a student
| whose teachers have not turned this on.
*/
it('answers the concept list with an empty set rather than a refusal', function (): void {
    $fx = adaptiveFixture(['easy', 'easy'], enabled: false);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    expect($this->getJson('/api/v1/practice/adaptive/concepts')->assertOk()->json('data'))->toBe([]);
});

it('works for a student who has no workspace context at all', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy']);

    // The person the product actually creates: enrolled, member of nothing.
    expect($fx['student']->fresh()->last_workspace_id)->toBeNull();

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated();

    expect($this->getJson('/api/v1/practice/adaptive/concepts')->assertOk()->json('data'))
        ->toHaveCount(1);
});

/*
| ⚠️ THE SWITCH IS READ WITH THE TEACHER'S ID AND NEVER FROM `WorkspaceContext`.
| That is null for every student and `(int) null === 0` addresses the PLATFORM
| default row — which ships off — so a context-based read refuses the feature to
| everybody while the teacher's own switch shows it enabled. This case removes the
| platform row entirely: a build reading the context would 403 here, and one
| reading the teacher's workspace is untouched.
*/
it('reads the teacher-s switch and not the platform default', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy']);

    DB::table('feature_flags')->where('key', 'adaptive_practice')->where('workspace_id', 0)->delete();
    app()->forgetInstance(Flags::class);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated();
});

it('refuses a teacher the student does not study with', function (): void {
    $fx = adaptiveFixture(['easy', 'easy']);
    $stranger = adaptiveFixture(['easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    // A workspace uuid that is real and is not theirs. It refuses rather than
    // falling through to a different teacher's bank.
    $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $stranger['workspace']->uuid,
    ])->assertStatus(422);
});
