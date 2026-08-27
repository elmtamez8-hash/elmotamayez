<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\ConversationWriteBan;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Actions\MoveMember;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| SC-010 — «يقرأُ ولا يكتب، ويكتبُ في خيطِه الخاصّ، ويعودُ بعدَ المدّةِ بلا تدخّل».
|
| ⚠️ الأداةُ الثالثةُ موجودةٌ لأنّ الاثنتَين القائمتَين بالوزنِ الخطأ. القفلُ يُسكِتُ
| الفصلَ كلَّه ليصلَ إلى واحد؛ والحظرُ على مستوى المساحةِ يغطّي كلَّ خيطٍ مع هذا
| المدرّسِ إلى الأبد. هذه بينهما: خيطٌ واحد، شخصٌ واحد، وله نهاية.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->fx = cohortFixture();
    $this->asGuest();

    app(JoinCohort::class)->handle($this->fx['a'], $this->fx['student']);
});

/**
 * Sign in, resetting the workspace context first.
 *
 * ⚠️ WITHOUT THE RESET THE TEACHER'S REQUEST HAS NO SPATIE TEAM ID, AND THEREFORE
 * NO ROLES AT ALL. `WorkspaceContext` is an application-wide singleton that CACHES
 * its resolution, and the student's Action in `beforeEach` resolves it to null — a
 * student is a member of no workspace. Every later request in the same process
 * then reuses that cached null, so `chat.moderate` is false and the teacher is
 * refused their own moderation button. In production each request is a fresh
 * process; here the reset is what makes the fixture resemble one.
 */
function signIn(User $user): void
{
    // The reset itself: a brand-new singleton, exactly what `asGuest()` does.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    Sanctum::actingAs($user);
}

/** The group's thread, as whoever is signed in sees it. */
function cohortRoom(object $test, mixed $cohortUuid): array
{
    return $test->getJson('/api/v1/cohorts/'.$cohortUuid.'/chat')->assertOk()->json();
}

function sayInto(object $test, mixed $conversationUuid): TestResponse
{
    return $test->postJson('/api/v1/conversations/'.$conversationUuid.'/messages', [
        'body' => 'سؤال عن الواجب.',
    ]);
}

it('lets a banned member read, refuses the write with a reason, and lapses on its own', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    // Before the ban: the member writes.
    sayInto($this, $room['uuid'])->assertCreated();

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
        'reason' => 'مقاطعة متكرّرة أثناء الشرح.',
        'minutes' => 10,
    ])->assertCreated();

    signIn($this->fx['student']);

    // ⚠️ READING IS UNTOUCHED, and that is the whole difference from the lock:
    // the class carries on and one person is quiet.
    $this->getJson('/api/v1/conversations/'.$room['uuid'].'/messages')->assertOk();

    $refusal = sayInto($this, $room['uuid'])->assertForbidden();

    /*
    | ⚠️ THE REASON IS READ OUT OF THE DECODED BODY, NEVER OUT OF `getContent()`.
    | That method escapes everything non-ASCII, so an Arabic needle matched
    | against it is vacuously absent whatever the payload holds — the defect this
    | repository records for every exposure test it owns.
    */
    $message = (string) $refusal->json('message');
    expect($message)->toContain('مقاطعة متكرّرة');

    // ⚠️ AND IT ENDS BY ITSELF. Nothing sweeps and nothing is dispatched — the
    // reader compares a timestamp, so the student is simply writing again after.
    $this->travel(11)->minutes();

    sayInto($this, $room['uuid'])->assertCreated();
});

/*
| ⚠️ THE PRIVATE THREAD STAYS OPEN, AND THIS IS THE CASE THAT SEPARATES THE THREE
| INSTRUMENTS. A student quieted in the group room must still be able to say «لم
| أفهم» to their teacher — take that away and the tool is a workspace ban wearing
| a smaller name, applied without the record a ban carries.
*/
it('leaves the student writing in their private thread with the teacher', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
        'reason' => 'مقاطعة.',
        'minutes' => 60,
    ])->assertCreated();

    signIn($this->fx['student']);

    $private = $this->postJson('/api/v1/conversations', [
        'workspace' => (string) $this->fx['workspace']->uuid,
    ])->assertCreated()->json();

    sayInto($this, $private['uuid'])->assertCreated();
});

/*
| ⚠️ AND THE BAN DOES NOT FOLLOW THEM. It is keyed on `conversation_id`, so this
| is true BY CONSTRUCTION rather than by a rule somebody remembers when transfers
| are written — which is exactly why it is asserted: the construction is the claim.
*/
it('does not follow the student into the group they move to', function (): void {
    signIn($this->fx['student']);
    $roomA = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$roomA['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
        'reason' => 'مقاطعة.',
    ])->assertCreated();

    // The teacher moves them by hand — no request, no approval (FR-028ط).
    $this->asGuest();
    app(MoveMember::class)->handle($this->fx['b'], $this->fx['student'], $this->fx['owner']);

    signIn($this->fx['student']);
    $roomB = cohortRoom($this, $this->fx['b']->uuid);

    sayInto($this, $roomB['uuid'])->assertCreated();

    // And still reading the one they left, which is FR-046.
    $this->getJson('/api/v1/conversations/'.$roomA['uuid'].'/messages')->assertOk();
});

/*
| ⚠️ AN OPEN BAN — `expires_at IS NULL` — MUST NOT READ AS EXPIRED, and it is the
| one ban that may never end on its own. `expires_at > now()` alone frees the
| person the instant they are silenced, on both engines, with nothing that errors.
*/
it('keeps an open-ended ban in force', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
        'reason' => 'حتى نتحدّث.',
    ])->assertCreated();

    signIn($this->fx['student']);
    $this->travel(30)->days();

    sayInto($this, $room['uuid'])->assertForbidden();
});

it('lets the teacher lift it before the clock runs out', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
        'reason' => 'مقاطعة.',
        'minutes' => 600,
    ])->assertCreated();

    $this->deleteJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['student']->uuid,
    ])->assertOk();

    signIn($this->fx['student']);

    sayInto($this, $room['uuid'])->assertCreated();

    /*
    | ⚠️ AND THE ROW IS STILL THERE. Lifting is a stamp, never a delete: a deleted
    | ban is a student who says they were silenced and a teacher with nothing to
    | show either way.
    */
    expect(ConversationWriteBan::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| ⚠️ THE TEACHER IS EXEMPT, for the reason the lock exempts them. A moderator
| silenced in a thread they run cannot answer the question on screen or say why
| anybody was quieted — and here the button that would do it sits in the same row
| as every student's.
*/
it('refuses to silence the moderator pressing the button', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);

    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $this->fx['owner']->uuid,
        'reason' => 'خطأ.',
    ])->assertStatus(404);
});

/*
| ⚠️ THE LOCK REACHES THE GROUP THREAD WITH NO NEW CODE, AND THAT IS WHAT THIS
| ASSERTS. `SetConversationLock` gates on `kind->isPublic()` and the cohort kind
| is public, so extending it would have been a rewrite of something already
| correct — but "already correct" is a claim, and this is the measurement of it.
|
| The moderator stays exempt: a teacher who closes the discussion and finds their
| own field disabled cannot answer the last question on screen or say why they
| closed it.
*/
it('closes the group discussion for members and leaves the teacher writing', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    signIn($this->fx['owner']);
    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/lock', ['locked' => true])->assertOk();

    // The teacher writes into the room they just closed.
    sayInto($this, $room['uuid'])->assertCreated();

    signIn($this->fx['student']);

    $this->getJson('/api/v1/conversations/'.$room['uuid'].'/messages')->assertOk();
    sayInto($this, $room['uuid'])->assertForbidden();
});

/*
| ⚠️ AND SOMEBODY WHO WAS NEVER IN THE ROOM CANNOT BE NAMED INTO IT. The first
| version of `target()` asked that question for a cohort room and answered `true`
| for every other kind — so a moderator could name ANY uuid on the platform into a
| session room and write a disciplinary-looking row about a person who was never
| there. That is NFR-001أ's identity probe with a record attached to it, and the
| `404` is deliberately the same answer as for a uuid that does not exist at all.
*/
it('refuses to silence somebody who was never in the room', function (): void {
    signIn($this->fx['student']);
    $room = cohortRoom($this, $this->fx['a']->uuid);

    $stranger = User::factory()->create();

    signIn($this->fx['owner']);

    $this->postJson('/api/v1/conversations/'.$room['uuid'].'/write-bans', [
        'user_uuid' => (string) $stranger->uuid,
        'reason' => 'خطأ.',
    ])->assertStatus(404);
});
