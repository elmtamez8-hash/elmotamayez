<?php

declare(strict_types=1);

use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Actions\ModerateReview;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-021 · FR-022 — hiding a message and banning a participant, both recorded.
|
| ⚠️ THE BAN IS WORKSPACE-WIDE, AND THAT IS MEASURED ON EVERY SURFACE. A ban that
| only stopped the room it was issued in would move the argument to the private
| chat within a minute — so the refusal is asserted in the session room, in the
| student's private conversation, AND at `StartConversation`, which is the door a
| banned person would otherwise use to open a fresh thread.
|
| ⚠️ AND LIFTING IS A NEW ROW, NEVER A DELETION. `moderation_actions` IS the
| record the requirement asks for; deleting the ban row erases who banned whom and
| why. Asserted by counting rows as well as by the behaviour.
|
| ⚠️ AND A PERMANENT BAN HAS `expires_at IS NULL`, WHICH ENDS IMMEDIATELY IF THE
| PREDICATE IS UNGROUPED. `NULL > now()` is NULL, so `WHERE … OR expires_at >
| now()` without parentheses around the pair reads as "not banned" for exactly the
| ban a moderator meant to make permanent. The same family as the legal-hold
| defect in 013.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->roomUuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    Sanctum::actingAs($this->student);

    $this->privateUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');
});

it('stops a banned participant writing anywhere in the workspace', function (): void {
    // The control: before the ban, all three doors are open.
    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'قبل'])->assertCreated();
    $this->postJson("/api/v1/conversations/{$this->privateUuid}/messages", ['body' => 'قبل'])->assertCreated();

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/moderation/actions', [
        'verdict' => ModerationVerdict::Banned->value,
        'subject_type' => 'user',
        'subject_uuid' => $this->student->uuid,
        'reason' => 'إساءة متكرّرة في غرفة الحصّة',
    ])->assertCreated();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'بعد'])->assertForbidden();
    $this->postJson("/api/v1/conversations/{$this->privateUuid}/messages", ['body' => 'بعد'])->assertForbidden();

    // ⚠️ AND THE DOOR TO A NEW THREAD, which a ban that only guarded `PostMessage`
    // would leave wide open.
    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertForbidden();

    // Reading survives: FR-022 stops the writing and says so, it does not delete
    // the room from under them.
    $this->getJson("/api/v1/conversations/{$this->roomUuid}/messages")->assertOk();
});

it('lifts a ban with a new row rather than by deleting the old one', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $ban = [
        'verdict' => ModerationVerdict::Banned->value,
        'subject_type' => 'user',
        'subject_uuid' => $this->student->uuid,
        'reason' => 'إساءة',
    ];

    $this->postJson('/api/v1/moderation/actions', $ban)->assertCreated();

    $this->postJson('/api/v1/moderation/actions', [
        ...$ban,
        'verdict' => ModerationVerdict::Unbanned->value,
        'reason' => 'اعتذر والتزم',
    ])->assertCreated();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'عدت'])->assertCreated();

    // ⚠️ `verdict` IS CAST TO THE ENUM, so the plucked values are enum instances
    // and a comparison against the string is false whatever the rows say.
    $rows = ModerationAction::query()->withoutWorkspaceScope()
        ->where('subject_id', $this->student->getKey())
        ->pluck('verdict')
        ->map(fn (ModerationVerdict $verdict): string => $verdict->value);

    // Two rows, not zero and not one: the ban and its lifting are both facts.
    expect($rows)->toHaveCount(2)
        ->and($rows->contains(ModerationVerdict::Banned->value))->toBeTrue()
        ->and($rows->contains(ModerationVerdict::Unbanned->value))->toBeTrue();
});

it('keeps a permanent ban permanent', function (): void {
    /*
    | The ban is written with no `expires_at`. If the reader's predicate is
    | ungrouped — `WHERE verdict = 'banned' AND expires_at IS NULL OR expires_at >
    | now()` — the row evaluates as expired and the student writes freely, one
    | second after being banned for good.
    */
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/moderation/actions', [
        'verdict' => ModerationVerdict::Banned->value,
        'subject_type' => 'user',
        'subject_uuid' => $this->student->uuid,
        'reason' => 'دائم',
    ])->assertCreated();

    expect(ModerationAction::query()->withoutWorkspaceScope()
        ->where('subject_id', $this->student->getKey())
        ->value('expires_at'))->toBeNull();

    $this->travel(400)->days();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'بعد سنة'])->assertForbidden();

    $this->travelBack();
});

it('lets a timed ban lapse on its own', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/moderation/actions', [
        'verdict' => ModerationVerdict::Banned->value,
        'subject_type' => 'user',
        'subject_uuid' => $this->student->uuid,
        'reason' => 'يومان',
        'expires_at' => now()->addDays(2)->toIso8601String(),
    ])->assertCreated();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'أثناء'])->assertForbidden();

    $this->travel(3)->days();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", ['body' => 'بعد'])->assertCreated();

    $this->travelBack();
});

it('hides a message on a moderator verdict and refuses a member who was not delegated it', function (): void {
    Sanctum::actingAs($this->student);

    $messageUuid = (string) $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", [
        'body' => 'كلام يُحذف',
    ])->assertCreated()->json('uuid');

    // An assistant who may ANSWER is not thereby a moderator: the two are
    // different permissions, ticked separately for a named person.
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($this->workspace, $assistant);
    $assistant->givePermissionTo(Permissions::CHAT_REPLY);

    Sanctum::actingAs($assistant->refresh());

    $hide = [
        'verdict' => ModerationVerdict::Hidden->value,
        'subject_type' => 'message',
        'subject_uuid' => $messageUuid,
        'reason' => 'خارج الموضوع',
    ];

    $this->postJson('/api/v1/moderation/actions', $hide)->assertForbidden();

    // Delegated deliberately, and the same request goes through — which is what
    // makes the refusal above the permission rather than a broken endpoint.
    $assistant->givePermissionTo(Permissions::CHAT_MODERATE);
    app()->forgetScopedInstances();

    Sanctum::actingAs($assistant->refresh());
    $this->postJson('/api/v1/moderation/actions', $hide)->assertCreated();

    Sanctum::actingAs($this->student);
    $this->getJson("/api/v1/conversations/{$this->roomUuid}/messages")->assertOk()->assertJsonCount(0);

    // The words are gone from the room and the row is not.
    $this->assertDatabaseHas('messages', ['uuid' => $messageUuid]);
});

/*
| FR-034 — a public review goes through the SAME moderation path.
|
| ⚠️ A REVIEW THAT WAS ALREADY HIDDEN ANSWERS EXACTLY AS ONE THAT NEVER EXISTED,
| and that is the pairing that matters. The uuids of VISIBLE reviews are public by
| construction — they are on the teacher's profile — so a 404 for an unknown one
| reveals nothing. What must not be distinguishable is «taken down» from «not
| real», and the `is_visible` condition inside the Action is what collapses them.
| `ReportMessage` answers 404 the same way for the same shape of miss.
*/
it('files a report against a review into the moderation record', function (): void {
    $workspace = marketplaceWorkspace();
    $teacher = marketplaceTeacher($workspace);
    $student = studentWhoAttendedWith($teacher);

    $reviewUuid = (string) postReview($student, $teacher->uuid, 1, 'تعليق مسيء')
        ->assertStatus(201)->json('uuid');

    // Anybody signed in who can read the profile may report what is on it.
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $this->postJson("/api/v1/reviews/{$reviewUuid}/report", ['reason' => 'إساءة'])
        ->assertStatus(202);

    $this->assertDatabaseHas('moderation_actions', [
        'subject_type' => ModerationAction::SUBJECT_REVIEW,
        'verdict' => ModerationVerdict::Reported->value,
    ]);

    // ⚠️ AND THE REVIEW IS STILL ON THE PROFILE. A report that hid content on
    // submission is a mute button handed to whoever complains first — and here it
    // would be aimed at a teacher's living.
    $review = Review::query()
        ->withoutWorkspaceScope()->where('uuid', $reviewUuid)->firstOrFail();

    expect($review->is_visible)->toBeTrue();

    // Hidden and never-existed are one answer.
    app(ModerateReview::class)->handle($review);

    $this->postJson("/api/v1/reviews/{$reviewUuid}/report", ['reason' => 'إساءة'])
        ->assertStatus(404);

    $this->postJson('/api/v1/reviews/'.fake()->uuid().'/report', ['reason' => 'إساءة'])
        ->assertStatus(404);
});
