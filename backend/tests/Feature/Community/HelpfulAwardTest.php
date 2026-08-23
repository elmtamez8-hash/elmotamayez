<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-023 — the teacher marks an answer useful, and the points are awarded once.
|
| ⚠️ TWO PRESSES, ONE AWARD, AND THERE ARE TWO GUARDS BEHIND THAT. The conditional
| `WHERE is_helpful = 0` update is the claim: it reports one affected row the
| first time and zero the second, and the EVENT is fired only by the winner. The
| award key `(student, action, source_type, source_id)` is the second layer, and
| it only exists because the first can be got wrong — a listener that fired on
| both presses and trusted the key would still be relying on a unique index to
| paper over a double-fire.
|
| ⚠️ AND THE ASSERTION IS ON THE TOTAL, NOT ON A ROW COUNT. `award_entries` uses
| `insertOrIgnore`, so counting one row is also what a design that awarded nothing
| at all produces; the balance moving exactly once is the fact the requirement is
| about. Same reading `AwardReversalTest` already wrote down.
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

    $this->messageUuid = (string) $this->postJson("/api/v1/conversations/{$this->roomUuid}/messages", [
        'body' => 'الجواب: نضرب الطرفَين في المقام.',
    ])->assertCreated()->json('uuid');
});

it('awards the points once however many times the teacher presses', function (): void {
    $xpBefore = (int) StudentProgress::query()
        ->where('user_id', $this->student->getKey())
        ->value('xp');

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/messages/{$this->messageUuid}/helpful")->assertOk();
    $this->postJson("/api/v1/messages/{$this->messageUuid}/helpful")->assertOk();

    $xpAfter = (int) StudentProgress::query()
        ->where('user_id', $this->student->getKey())
        ->value('xp');

    $catalogue = (int) GamificationAction::query()
        ->where('key', 'helpful_answer')
        ->value('xp');

    expect($catalogue)->toBeGreaterThan(0, 'the catalogue row must exist or every assertion here passes against zero')
        ->and($xpAfter - $xpBefore)->toBe($catalogue)
        ->and(AwardEntry::query()
            ->where('student_user_id', $this->student->getKey())
            ->where('action_key', 'helpful_answer')
            ->count())->toBe(1);
});

it('marks the message and says so in the payload', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    expect($this->postJson("/api/v1/messages/{$this->messageUuid}/helpful")->assertOk()->json('is_helpful'))
        ->toBeTrue();

    Sanctum::actingAs($this->student);

    $rows = $this->getJson("/api/v1/conversations/{$this->roomUuid}/messages")->assertOk()->json();

    expect($rows[0]['is_helpful'])->toBeTrue();
});

it('refuses the mark to a student, including the author of the message', function (): void {
    /*
    | ⚠️ THE AUTHOR ESPECIALLY. FR-023 makes this a teacher's endorsement, and an
    | endorsement anybody can give themselves is a points button on every message
    | a student writes.
    */
    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/messages/{$this->messageUuid}/helpful")->assertForbidden();
});
