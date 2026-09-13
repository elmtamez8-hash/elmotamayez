<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T076 · FR-029 — THE HOUR IN PROGRESS IS NOT EDITABLE.
|
| ⚠️ AND THE EXISTING GUARD DOES NOT COVER IT. `UpdateClassSession` refuses a
| TERMINAL session and nothing else, so a test written against a finished lesson
| is green today and measures none of this. The session this file edits is
| `Live`, with its room open — the state the refusal is actually for.
|
| What it costs without the guard is not a tidiness point. The stay bar is half
| the DURATION (٠٣٥ · FR-005), computed when the register closes: a teacher who
| drops a sixty-minute lesson to ten in its fiftieth minute moves that bar from
| thirty minutes to five, so everyone who looked in briefly is charged a credit
| and the teacher is paid for every one of them. Moving `starts_at` is the same
| lever from the other end, and it also drags the cancellation deadline onto a
| moment that has already passed.
|
| ⛔ THE CONDITION IS THE STATUS, NEVER `room_opened_at`. Fixtures across this
| suite stamp `live` with no timestamp; keyed on the column, forty existing tests
| become confident claims about a lesson that never happened.
|
| ⛔ AND THE REFUSAL NAMES THE TWO FIELDS RATHER THAN LOCKING THE ACTION. There is
| a third caller with no FormRequest above it — `DecideSessionRescheduleRequest`
| goes straight to this Action with `starts_at` — so a blanket refusal would take
| the reschedule decision down with it. The last case here is the control: an
| edit that touches neither field still goes through on a live session.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(2),
        'ends_at' => CarbonImmutable::now()->addMinutes(62),
        'duration_minutes' => 60,
        'seats_total' => 10,
    ]);

    // The room, opened. This is what makes the session `Live`, and it is the only
    // writer of that status in the tree.
    app(OpenBroadcastRoom::class)->handle($this->session->refresh());
    $this->session->refresh();

    expect($this->session->status)->toBe(ClassSessionStatus::Live);
});

it('refuses to shorten a lesson that is being taught', function (): void {
    expect(fn () => app(UpdateClassSession::class)->handle(
        $this->session->refresh(),
        ['duration_minutes' => 10],
    ))->toThrow(DomainException::class);

    expect((int) $this->session->refresh()->duration_minutes)->toBe(60);
});

it('refuses to move a lesson that is being taught', function (): void {
    expect(fn () => app(UpdateClassSession::class)->handle(
        $this->session->refresh(),
        ['starts_at' => CarbonImmutable::now()->addHours(3)->toIso8601String()],
    ))->toThrow(DomainException::class);
});

it('answers the teacher with a field error rather than a raw failure', function (): void {
    // The other door: the FormRequest, so the screen lands the message under the
    // field instead of showing the Action's exception through the generic handler.
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/class-sessions/'.$this->session->uuid, [
        'duration_minutes' => 10,
    ])->assertStatus(422)->assertJsonValidationErrors(['duration_minutes']);
});

it('still allows an edit that touches neither the clock nor the duration', function (): void {
    $updated = app(UpdateClassSession::class)->handle(
        $this->session->refresh(),
        ['title' => 'مراجعة الفصل الثالث'],
    );

    // ⚠️ THE CONTROL, and it is not decoration. A refusal written as «no editing
    // while live» passes the two cases above and silently kills the reschedule
    // decision, which reaches this Action with no FormRequest in front of it.
    expect($updated->title)->toBe('مراجعة الفصل الثالث')
        ->and((int) $updated->duration_minutes)->toBe(60);
});
