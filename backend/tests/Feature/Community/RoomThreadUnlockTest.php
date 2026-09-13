<?php

declare(strict_types=1);

use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Policies\ConversationPolicy;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · FR-013ب · R10 · T026 — PAYING OPENS THE ROOM'S THREAD FOR READING AND
| NOT FOR WRITING.
|
| ⛔ AND THE SUBJECT IS A STUDENT WHO NEVER HELD A SEAT. That is the whole
| fixture and it is the one thing a careless version of this file gets wrong.
| The obvious subject is the EXCUSED student — they are the ordinary buyer — but
| they still hold their booking, so `hasSeatInSession()` is true for them and
| they may write for a reason that has nothing to do with what they paid. A file
| built on them measures nothing: it is green whether or not the write door was
| ever widened.
|
| ⚠️ THE HAZARD IS STRUCTURAL, NOT HYPOTHETICAL. `post()` calls `view()` first,
| and the ONLY seat check in the room branch lives inside `view()`. So widening
| the read to admit whoever opened the hour GRANTS THE WRITE SILENTLY — no line
| anywhere says so — and a student who was not in the lesson starts typing into
| a thread every seat holder reads, for the price of one credit.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 3);

    // A seat holder, so the room is a real room with somebody in it — and so the
    // session is delivered rather than empty.
    $this->attender = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->attender);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->attender, $this->course);
    app(BookSeat::class)->handle($this->session->refresh(), $this->attender);

    // ⛔ THE SUBJECT: enrolled, funded, and never booked. See the file docblock.
    $this->outsider = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->outsider);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->outsider, $this->course);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, [$this->attender]);

    $this->room = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => ConversationKind::Session,
        'class_session_id' => $this->session->getKey(),
        'subject' => 'نقاش الحصّة',
    ]);
});

function roomPolicy(): ConversationPolicy
{
    return app(ConversationPolicy::class);
}

it('refuses both doors to the student who never held a seat and has not paid', function (): void {
    // The control. Without it the case below proves only that the room exists.
    expect(roomPolicy()->view($this->outsider, $this->room)->allowed())->toBeFalse()
        ->and(roomPolicy()->post($this->outsider, $this->room)->allowed())->toBeFalse();
});

it('opens the thread for reading and keeps it shut for writing once they pay', function (): void {
    app(UnlockSessionContent::class)->handle($this->outsider, $this->session->refresh());

    expect(roomPolicy()->view($this->outsider, $this->room)->allowed())->toBeTrue()
        /*
        | ⛔ THE ASSERTION THIS FILE EXISTS FOR. It fails on the obvious
        | implementation — widening `publicRoom()` alone — because `post()`
        | reaches the room's `Response::allow()` through `view()` and there is no
        | second seat check anywhere on the way. The refusal has to be written
        | out explicitly in `post()`, and this line is what says so.
        */
        ->and(roomPolicy()->post($this->outsider, $this->room)->allowed())->toBeFalse();
});

it('leaves the seat holder writing exactly as before', function (): void {
    /*
    | ⚠️ THE GUARD ON THE GUARD. An explicit refusal in `post()` written one
    | condition too wide silences the whole room — and the person it would
    | silence first is the student who actually sat in the lesson.
    */
    expect(roomPolicy()->view($this->attender, $this->room)->allowed())->toBeTrue()
        ->and(roomPolicy()->post($this->attender, $this->room)->allowed())->toBeTrue();
});

it('leaves the teacher writing in the room they ran', function (): void {
    // A teacher holds no seat of their own — `chat.moderate` is the exemption,
    // the same one the discussion lock and the ejection both read.
    expect(roomPolicy()->post($this->owner, $this->room)->allowed())->toBeTrue();
});
