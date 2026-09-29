<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the ROOM under a session (and, through the same helper,
| under a lesson and a group) — the staff branch of `ConversationPolicy::view()`
| and `moderate()`, the moderator's hide (`ModerateMessage`) and the helpful
| mark (`MessagePolicy::markHelpful`).
|
| ⛔ Until 2026-09-29 a confined assistant holding `chat.reply` / `chat.moderate`
| read, wrote into, locked, banned in, hid lines in and endorsed answers in the
| room of every session in the workspace — while `ClassSessionPolicy` (#286)
| already refused them the far session itself.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST, and the seat holder in every test: the scope
| lives on the staff branch alone, and a student's room must not move.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearSession = billableSession($this->workspace, $this->owner, $this->near, seatsTotal: 5);
    $this->farSession = billableSession($this->workspace, $this->owner, $this->far, seatsTotal: 5);
    $this->courseless = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => TeacherProfile::query()->where('user_id', $this->owner->getKey())->value('id'),
        'course_id' => null,
        'seats_total' => 5,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    // One seat holder in all three rooms.
    $this->seated = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->near, $this->seated);
    $this->createEnrollment($this->workspace, $this->far, $this->seated);

    foreach ([$this->nearSession, $this->farSession, $this->courseless] as $session) {
        SessionBooking::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $this->seated->getKey(),
            'status' => BookingStatus::Booked,
        ]);
    }

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    // The assistant role carries neither; the owner ticks them on.
    $this->assistant->givePermissionTo([Permissions::CHAT_REPLY, Permissions::CHAT_MODERATE]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->assistant->unsetRelation('permissions');

    // The rooms, opened by the teacher, each with one line from the student.
    $this->rooms = [];
    $this->studentLines = [];

    foreach (['near' => $this->nearSession, 'far' => $this->farSession, 'courseless' => $this->courseless] as $label => $session) {
        Sanctum::actingAs($this->owner);
        $this->rooms[$label] = (string) $this->getJson("/api/v1/class-sessions/{$session->uuid}/chat")
            ->assertOk()->json('uuid');

        Sanctum::actingAs($this->seated);
        $this->studentLines[$label] = (string) $this->postJson("/api/v1/conversations/{$this->rooms[$label]}/messages", [
            'body' => 'سؤال عن الدرس',
        ])->assertCreated()->json('uuid');
    }
});

function confineRoomAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/** Every staff door on one room, as HTTP statuses. */
function roomStaffStatuses(string $sessionUuid, string $roomUuid, string $studentLine, User $student): array
{
    $t = test();

    return [
        'resolve' => $t->getJson("/api/v1/class-sessions/{$sessionUuid}/chat")->status(),
        'read' => $t->getJson("/api/v1/conversations/{$roomUuid}/messages")->status(),
        'write' => $t->postJson("/api/v1/conversations/{$roomUuid}/messages", ['body' => 'إجابة'])->status(),
        'channel' => subscribeToChannel("private-conversation.{$roomUuid}")->status(),
        'helpful' => $t->postJson("/api/v1/messages/{$studentLine}/helpful")->status(),
        'lock' => $t->postJson("/api/v1/conversations/{$roomUuid}/lock", ['locked' => true])->status(),
        'unlock' => $t->postJson("/api/v1/conversations/{$roomUuid}/lock", ['locked' => false])->status(),
        'ban' => $t->postJson("/api/v1/conversations/{$roomUuid}/write-bans", [
            'user_uuid' => (string) $student->uuid,
            'reason' => 'مقاطعة متكرّرة',
            'minutes' => 10,
        ])->status(),
        'lift' => $t->deleteJson("/api/v1/conversations/{$roomUuid}/write-bans", [
            'user_uuid' => (string) $student->uuid,
        ])->status(),
        'hide' => $t->postJson('/api/v1/moderation/actions', [
            'verdict' => 'hidden',
            'subject_type' => 'message',
            'subject_uuid' => $studentLine,
            'reason' => 'خارج الموضوع',
        ])->status(),
    ];
}

const ROOM_ALLOWED = [
    'resolve' => 200, 'read' => 200, 'write' => 201, 'channel' => 200, 'helpful' => 200,
    'lock' => 200, 'unlock' => 200, 'ban' => 201, 'lift' => 200, 'hide' => 201,
];

it('lets a confined assistant run their own course\'s room and refuses the far and the course-less one', function (): void {
    confineRoomAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(roomStaffStatuses($this->nearSession->uuid, $this->rooms['near'], $this->studentLines['near'], $this->seated))
        ->toBe(ROOM_ALLOWED);

    foreach (['far' => $this->farSession, 'courseless' => $this->courseless] as $label => $session) {
        foreach (roomStaffStatuses($session->uuid, $this->rooms[$label], $this->studentLines[$label], $this->seated) as $door => $status) {
            expect($status)->toBe(403, "{$label} {$door}");
        }
    }

    // Nothing was written through a refused door.
    expect(Message::query()->withoutWorkspaceScope()->whereKey(
        Message::query()->withoutWorkspaceScope()->where('uuid', $this->studentLines['far'])->value('id'),
    )->value('hidden_at'))->toBeNull()
        ->and(Conversation::query()->withoutWorkspaceScope()->where('uuid', $this->rooms['far'])->value('locked_at'))->toBeNull();
});

it('leaves an unconfined assistant and the owner every room', function (): void {
    foreach (['assistant' => $this->assistant, 'owner' => $this->owner] as $who => $user) {
        Sanctum::actingAs($user);

        foreach (['near' => $this->nearSession, 'far' => $this->farSession, 'courseless' => $this->courseless] as $label => $session) {
            // Each run hides the line; a fresh one keeps `hide` and `helpful` meaningful.
            Sanctum::actingAs($this->seated);
            $line = (string) $this->postJson("/api/v1/conversations/{$this->rooms[$label]}/messages", ['body' => 'سؤال آخر'])
                ->assertCreated()->json('uuid');
            Sanctum::actingAs($user);

            expect(roomStaffStatuses($session->uuid, $this->rooms[$label], $line, $this->seated))
                ->toBe(ROOM_ALLOWED, "{$who} {$label}");
        }
    }
});

it('leaves the seat holder\'s room untouched while the assistant is confined', function (): void {
    confineRoomAssistantTo($this->near);
    Sanctum::actingAs($this->seated);

    foreach (['near' => $this->nearSession, 'far' => $this->farSession, 'courseless' => $this->courseless] as $label => $session) {
        $this->getJson("/api/v1/class-sessions/{$session->uuid}/chat")->assertOk();
        $this->getJson("/api/v1/conversations/{$this->rooms[$label]}/messages")->assertOk();
        $this->postJson("/api/v1/conversations/{$this->rooms[$label]}/messages", ['body' => 'شكراً'])->assertCreated();
        subscribeToChannel("private-conversation.{$this->rooms[$label]}")->assertOk();

        // Still a student: no moderation through the room.
        $this->postJson("/api/v1/conversations/{$this->rooms[$label]}/lock", ['locked' => true])->assertForbidden();
    }
});

it('refuses another workspace\'s room to a confined assistant and to its owner', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    $otherCourse = Course::factory()->published()->create(['workspace_id' => $other->getKey()]);
    $otherSession = billableSession($other, $otherOwner, $otherCourse, seatsTotal: 5);

    $this->setCurrentWorkspace($other, $otherOwner);
    Sanctum::actingAs($otherOwner);
    $otherRoom = (string) $this->getJson("/api/v1/class-sessions/{$otherSession->uuid}/chat")->assertOk()->json('uuid');

    // The other owner reaches nothing of ours, and our confined assistant nothing of theirs.
    $this->getJson("/api/v1/conversations/{$this->rooms['near']}/messages")->assertForbidden();

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    confineRoomAssistantTo($this->near);
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/class-sessions/{$otherSession->uuid}/chat")->assertForbidden();
    subscribeToChannel("private-conversation.{$otherRoom}")->assertForbidden();
});

it('asks the lesson\'s and the group\'s course for their rooms too', function (): void {
    confineRoomAssistantTo($this->near);

    $room = function (Course $course, string $kind): Conversation {
        if ($kind === 'lesson') {
            $lesson = Lesson::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'course_id' => $course->getKey(),
            ]);

            return new Conversation([
                'workspace_id' => $this->workspace->getKey(),
                'kind' => ConversationKind::Lesson,
                'lesson_id' => $lesson->getKey(),
            ]);
        }

        $cohort = Cohort::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $course->getKey(),
        ]);

        return new Conversation([
            'workspace_id' => $this->workspace->getKey(),
            'kind' => ConversationKind::Cohort,
            'cohort_id' => $cohort->getKey(),
        ]);
    };

    foreach (['lesson', 'cohort'] as $kind) {
        $near = $room($this->near, $kind);
        $far = $room($this->far, $kind);

        foreach (['view', 'moderate'] as $ability) {
            expect(Gate::forUser($this->assistant)->allows($ability, $near))->toBeTrue("{$kind} near {$ability}")
                ->and(Gate::forUser($this->assistant)->allows($ability, $far))->toBeFalse("{$kind} far {$ability}")
                ->and(Gate::forUser($this->owner)->allows($ability, $far))->toBeTrue("{$kind} owner {$ability}");
        }
    }
});

/*
| ⛔ A SCOPE REFUSAL FALLS THROUGH TO THE STUDENT'S DOORS (owner decision
| 2026-09-29). A confined assistant who ALSO holds a student's entitlement in a
| room outside their courses — a seat, an enrolment, a group — gets exactly a
| student's access there: the read, the pen on a student's terms, and no staff
| power at all. Somebody with no such entitlement stays refused.
*/

it('gives a confined assistant with a seat in a far session a student\'s access to its room and nothing more', function (): void {
    confineRoomAssistantTo($this->near);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->farSession->getKey(),
        'student_user_id' => $this->assistant->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    Sanctum::actingAs($this->assistant);

    expect(roomStaffStatuses($this->farSession->uuid, $this->rooms['far'], $this->studentLines['far'], $this->seated))
        ->toBe([
            'resolve' => 200, 'read' => 200, 'write' => 201, 'channel' => 200, 'helpful' => 403,
            'lock' => 403, 'unlock' => 403, 'ban' => 403, 'lift' => 403, 'hide' => 403,
        ]);

    // Nothing was written through a refused door.
    $farLine = Message::query()->withoutWorkspaceScope()->where('uuid', $this->studentLines['far'])->firstOrFail();
    expect($farLine->hidden_at)->toBeNull()
        ->and(Gate::forUser($this->assistant)->allows('markHelpful', $farLine))->toBeFalse()
        ->and(Conversation::query()->withoutWorkspaceScope()->where('uuid', $this->rooms['far'])->value('locked_at'))->toBeNull();

    // The lock binds them as it binds any student — `chat.moderate` does not
    // exempt them in a room outside their scope. They keep the archive.
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/conversations/{$this->rooms['far']}/lock", ['locked' => true])->assertOk();

    Sanctum::actingAs($this->assistant);
    $this->postJson("/api/v1/conversations/{$this->rooms['far']}/messages", ['body' => 'سؤال'])->assertForbidden();
    $this->getJson("/api/v1/conversations/{$this->rooms['far']}/messages")->assertOk();

    // And so does a per-thread write-ban the teacher puts on them.
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/conversations/{$this->rooms['far']}/lock", ['locked' => false])->assertOk();
    $this->postJson("/api/v1/conversations/{$this->rooms['far']}/write-bans", [
        'user_uuid' => (string) $this->assistant->uuid,
        'reason' => 'مقاطعة متكرّرة',
        'minutes' => 10,
    ])->assertCreated();

    Sanctum::actingAs($this->assistant);
    $this->postJson("/api/v1/conversations/{$this->rooms['far']}/messages", ['body' => 'سؤال'])->assertForbidden();

    // Their own course's room is still theirs to run.
    expect(roomStaffStatuses($this->nearSession->uuid, $this->rooms['near'], $this->studentLines['near'], $this->seated))
        ->toBe(ROOM_ALLOWED);
});

it('still refuses a confined assistant with no seat — an enrolment in the course is not one', function (): void {
    confineRoomAssistantTo($this->near);
    $this->createEnrollment($this->workspace, $this->far, $this->assistant);
    Sanctum::actingAs($this->assistant);

    foreach (roomStaffStatuses($this->farSession->uuid, $this->rooms['far'], $this->studentLines['far'], $this->seated) as $door => $status) {
        expect($status)->toBe(403, "far {$door}");
    }
});

it('gives a confined assistant a student\'s access to a far lesson room and group room they are entitled to', function (): void {
    confineRoomAssistantTo($this->near);

    $lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->far->getKey(),
    ]);
    $lessonRoom = new Conversation([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => ConversationKind::Lesson,
        'lesson_id' => $lesson->getKey(),
    ]);

    $cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->far->getKey(),
    ]);
    $cohortRoom = new Conversation([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => ConversationKind::Cohort,
        'cohort_id' => $cohort->getKey(),
    ]);

    // No entitlement yet: refused, as before.
    foreach (['lesson' => $lessonRoom, 'cohort' => $cohortRoom] as $kind => $room) {
        expect(Gate::forUser($this->assistant)->allows('view', $room))->toBeFalse("{$kind} view without entitlement");
    }

    $this->createEnrollment($this->workspace, $this->far, $this->assistant);
    $membership = CohortMembership::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $cohort->getKey(),
        'course_id' => $this->far->getKey(),
        'student_user_id' => $this->assistant->getKey(),
    ]);
    app()->forgetScopedInstances();

    foreach (['lesson' => $lessonRoom, 'cohort' => $cohortRoom] as $kind => $room) {
        $gate = Gate::forUser($this->assistant);

        expect($gate->allows('view', $room))->toBeTrue("{$kind} view")
            ->and($gate->allows('post', $room))->toBeTrue("{$kind} post")
            ->and($gate->allows('moderate', $room))->toBeFalse("{$kind} moderate")
            ->and($gate->allows('staffSide', $room))->toBeFalse("{$kind} staffSide");
    }

    // Left the group: reads the archive and — like any former member, unlike a
    // moderator — no longer writes there.
    $membership->forceFill(['closed_at' => now(), 'closed_slot' => $membership->getKey()])->save();
    app()->forgetScopedInstances();

    expect(Gate::forUser($this->assistant)->allows('view', $cohortRoom))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('post', $cohortRoom))->toBeFalse();
});
