<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 — the three chat doors #287 left open to a CONFINED
| assistant (owner decision 2026-09-29):
|
|   1. the WORKSPACE ban (`subject_type = user`) — only a student of their own
|      courses (`ModerationActionPolicy::banPerson()`);
|   2. the conversation LIST — a room outside their courses only when they may
|      read it as a student;
|   3. `can_moderate` — the scope as well as the permission, so no button answers
|      403.
|
| ⚠️ NEAR AND FAR IN EVERY TEST, and the owner and an unconfined assistant beside
| the confined one: the scope narrows staff and nobody else.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->near, $this->nearStudent);

    $this->farStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->far, $this->farStudent);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    $this->assistant->givePermissionTo([Permissions::CHAT_REPLY, Permissions::CHAT_MODERATE]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->assistant->unsetRelation('permissions');

    $this->confine = function (Course $course): void {
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $this->assignment->getKey(),
            'course_id' => $course->getKey(),
        ]);

        // The directory memoises per request; a fresh container is the next request.
        app()->forgetScopedInstances();
    };

    /*
    | A session room with one line in it and a participant row for `$reader` —
    | the only road by which a room reaches a staff member's list.
    */
    $this->roomFor = function (Course $course, User $reader): array {
        $session = billableSession($this->workspace, $this->owner, $course, seatsTotal: 5);

        $room = Conversation::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'kind' => ConversationKind::Session,
            'class_session_id' => $session->getKey(),
            'student_user_id' => null,
        ]);
        $line = Message::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'conversation_id' => $room->getKey(),
            'sender_user_id' => $this->owner->getKey(),
        ]);
        $room->forceFill(['last_message_id' => $line->getKey()])->save();

        ConversationParticipant::factory()->create([
            'conversation_id' => $room->getKey(),
            'user_id' => $reader->getKey(),
        ]);

        return [$session, $room];
    };

    $this->ban = fn (User $subject, string $verdict = 'banned') => $this->postJson('/api/v1/moderation/actions', [
        'verdict' => $verdict,
        'subject_type' => 'user',
        'subject_uuid' => (string) $subject->uuid,
        'reason' => 'إساءة متكرّرة',
    ]);
});

/** Conversation uuid => can_moderate, from the list. */
function leftoverListed(): array
{
    return collect(test()->getJson('/api/v1/conversations')->assertOk()->json())
        ->mapWithKeys(fn (array $row): array => [$row['uuid'] => $row['can_moderate']])
        ->all();
}

// ─── 1. The workspace ban ────────────────────────────────────────────────────

it('lets a confined assistant ban and lift a student of their own course only', function (): void {
    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    ($this->ban)($this->nearStudent)->assertCreated();
    ($this->ban)($this->nearStudent, 'unbanned')->assertCreated();

    // A student of a far course, a colleague and the owner: the teacher's call.
    foreach (['far student' => $this->farStudent, 'owner' => $this->owner] as $who => $subject) {
        expect(($this->ban)($subject)->status())->toBe(403, $who);
    }

    // The lift is the same door: the owner's ban on a far student stays theirs.
    Sanctum::actingAs($this->owner);
    ($this->ban)($this->farStudent)->assertCreated();
    Sanctum::actingAs($this->assistant);
    ($this->ban)($this->farStudent, 'unbanned')->assertForbidden();

    expect(ModerationAction::query()->withoutWorkspaceScope()
        ->where('actor_user_id', $this->assistant->getKey())
        ->where('subject_id', '!=', $this->nearStudent->getKey())
        ->exists())->toBeFalse();
});

it('leaves the workspace ban to the owner and to an unconfined assistant as before', function (): void {
    foreach (['assistant' => $this->assistant, 'owner' => $this->owner] as $who => $actor) {
        Sanctum::actingAs($actor);

        foreach ([$this->nearStudent, $this->farStudent] as $subject) {
            expect(($this->ban)($subject)->status())->toBe(201, $who)
                ->and(($this->ban)($subject, 'unbanned')->status())->toBe(201, $who);
        }
    }
});

it('refuses a confined assistant a student whose only enrolment is in another workspace', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    $otherCourse = Course::factory()->published()->create(['workspace_id' => $other->getKey()]);
    $stranger = $this->addWorkspaceMember($other, Roles::STUDENT);
    $this->createEnrollment($other, $otherCourse, $stranger);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    ($this->ban)($stranger)->assertForbidden();
});

// ─── 2 & 3. The list, and `can_moderate` on it ───────────────────────────────

it('lists a confined assistant\'s own rooms and students with moderation, and hides the far ones', function (): void {
    [, $nearRoom] = ($this->roomFor)($this->near, $this->assistant);
    [, $farRoom] = ($this->roomFor)($this->far, $this->assistant);

    foreach ([$this->nearStudent, $this->farStudent] as $student) {
        Sanctum::actingAs($student);
        $this->postJson('/api/v1/conversations', ['body' => 'سؤال', 'workspace' => $this->workspace->uuid])->assertCreated();
    }
    $nearThread = Conversation::query()->withoutWorkspaceScope()->where('student_user_id', $this->nearStudent->getKey())->value('uuid');
    $farThread = Conversation::query()->withoutWorkspaceScope()->where('student_user_id', $this->farStudent->getKey())->value('uuid');

    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    expect(leftoverListed())->toEqualCanonicalizing([
        $nearRoom->uuid => true,
        $nearThread => true,
    ]);

    // Unconfined, and the owner (who reads rooms through a participant row too):
    // everything, with moderation everywhere.
    AssistantScope::query()->where('assistant_assignment_id', $this->assignment->getKey())->delete();
    app()->forgetScopedInstances();

    expect(leftoverListed())->toEqualCanonicalizing([
        $nearRoom->uuid => true, $farRoom->uuid => true, $nearThread => true, $farThread => true,
    ]);

    ConversationParticipant::factory()->create(['conversation_id' => $farRoom->getKey(), 'user_id' => $this->owner->getKey()]);
    Sanctum::actingAs($this->owner);

    expect(leftoverListed())->toMatchArray([$farRoom->uuid => true, $farThread => true, $nearThread => true]);
});

it('lists a far room to a confined assistant who holds a seat in it, without moderation', function (): void {
    [$farSession, $farRoom] = ($this->roomFor)($this->far, $this->assistant);
    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    expect(leftoverListed())->not->toHaveKey($farRoom->uuid);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $farSession->getKey(),
        'student_user_id' => $this->assistant->getKey(),
        'status' => BookingStatus::Booked,
    ]);
    app()->forgetScopedInstances();

    expect(leftoverListed())->toBe([$farRoom->uuid => false]);
});

it('answers can_moderate by the scope on a single room too', function (): void {
    $nearSession = billableSession($this->workspace, $this->owner, $this->near, seatsTotal: 5);
    $farSession = billableSession($this->workspace, $this->owner, $this->far, seatsTotal: 5);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $farSession->getKey(),
        'student_user_id' => $this->assistant->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    $open = fn (ClassSession $session) => $this->getJson("/api/v1/class-sessions/{$session->uuid}/chat")
        ->assertOk()->json('can_moderate');

    // Unconfined and the owner: both rooms.
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);
        expect($open($nearSession))->toBeTrue()->and($open($farSession))->toBeTrue();
    }

    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    // The far room opens through the seat — as a student, with no buttons.
    expect($open($nearSession))->toBeTrue()
        ->and($open($farSession))->toBeFalse();

    // The seat holder never moderates.
    Sanctum::actingAs($this->nearStudent);
    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $nearSession->getKey(),
        'student_user_id' => $this->nearStudent->getKey(),
        'status' => BookingStatus::Booked,
    ]);
    expect($open($nearSession))->toBeFalse();
});

it('lists a confined assistant\'s rooms at a constant cost whatever their number', function (): void {
    ($this->roomFor)($this->near, $this->assistant);
    ($this->roomFor)($this->far, $this->assistant);
    ($this->confine)($this->near);
    Sanctum::actingAs($this->assistant);

    // Warm-up until steady — see `ChatQueryBudgetTest`.
    for ($i = 0; $i < 2; $i++) {
        $this->getJson('/api/v1/conversations')->assertOk();
        app()->forgetScopedInstances();
    }

    [$small, $smallResponse] = countingQueries(fn () => $this->getJson('/api/v1/conversations')->assertOk());

    for ($i = 0; $i < 6; $i++) {
        ($this->roomFor)($this->near, $this->assistant);
    }
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->assistant);

    [$large, $largeResponse] = countingQueries(fn () => $this->getJson('/api/v1/conversations')->assertOk());

    expect($large)->toBe($small)
        ->and($smallResponse->json('*.can_moderate'))->toBe([true])
        ->and($largeResponse->json('*.can_moderate'))->toBe(array_fill(0, 7, true));
});
