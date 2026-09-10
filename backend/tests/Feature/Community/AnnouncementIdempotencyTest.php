<?php

declare(strict_types=1);

use App\Modules\Community\Actions\PublishAnnouncement;
use App\Modules\Community\Events\AnnouncementPublished;
use App\Modules\Community\Jobs\FanOutAnnouncementJob;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Support\AnnouncementAudience;
use App\Modules\Courses\Models\Course;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| Two presses are one fan-out, and a worker killed halfway tells nobody twice.
|
| ⚠️ TWO SEPARATE GUARDS, AND EACH ONE FAILS `SC-016` FROM A DIFFERENT SIDE.
|
| The conditional claim on `published_at` stops the SECOND publish: without it
| two taps on a slow connection both read null, both write, and two fan-outs
| start over the same three hundred students — at which point the per-recipient
| diff is racing itself and protects nobody, because both runners read «not yet
| notified» for the same person.
|
| The per-recipient diff stops the RE-RUN: a queue retry, a worker killed
| mid-chain, or a manual re-dispatch from zero must resume without telling anyone
| again. That is also why the job does not pin `tries: 1` — a retry is safe by
| construction, and refusing one turns a single dropped connection into a partial
| delivery nobody notices, which is the silent half of the same criterion.
|
| ⚠️ AND THE KEYSET IS THE THIRD LEG. Were the diff the only mechanism, a chunk
| whose renders all failed would be re-read for ever and the students after it
| would never be reached — green in a fixture small enough to fit one chunk.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->students = collect(range(1, 3))->map(function (): mixed {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);

        return $student;
    });
});

function countTold(Announcement $announcement): int
{
    return Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('source_id', $announcement->getKey())
        ->count();
}

it('publishes once however many times the button is pressed', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', [
        'body' => 'اختبار الغد مؤجَّل.',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    // The second press succeeds — the teacher's request was valid — and starts
    // nothing. A 409 here would be a screen telling somebody they broke
    // something by double-tapping.
    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    expect(countTold($announcement))->toBe(3);
});

it('fires the event only for the runner that claimed the row', function (): void {
    /*
    | ⚠️ THIS ASSERTS ON THE EVENT, AND THE ROW COUNT WOULD HAVE PROVED NOTHING.
    |
    | Measured, not reasoned about: deleting `whereNull('published_at')` from the
    | Action left every other case in this file green. The diff masks it —
    | sequentially, the second fan-out reads three recipients who have already
    | been told and drops all three. So a test counting notifications is
    | measuring the diff while claiming to measure the claim, which is the
    | «green because of the wrong condition» defect this repository already
    | recorded once, in spec 013's US6.
    |
    | What the claim actually guards is CONCURRENCY, which no single-threaded
    | test can stage: two runners publishing at the same instant both start a
    | fan-out, and then both read «not yet notified» for the same student inside
    | the same window. One event is the only observable that says the second
    | fan-out never began.
    */
    Event::fake([AnnouncementPublished::class]);

    $announcement = Announcement::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'author_user_id' => $this->teacher->getKey(),
    ]);

    $action = app(PublishAnnouncement::class);

    $action->handle($announcement);
    // The loser is passed the STALE model on purpose: a runner that lost the
    // race holds exactly this — `published_at` still null in memory.
    $action->handle($announcement);

    Event::assertDispatchedTimes(AnnouncementPublished::class, 1);
});

it('tells nobody twice when the job is run again from the start', function (): void {
    $announcement = Announcement::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'author_user_id' => $this->teacher->getKey(),
    ]);

    // The whole walk, then the whole walk again — a worker killed and restarted,
    // or a queue retry of the first link.
    (new FanOutAnnouncementJob((int) $announcement->getKey()))->handle(
        app(AnnouncementAudience::class),
        app(DispatchNotification::class),
    );

    expect(countTold($announcement))->toBe(3);

    (new FanOutAnnouncementJob((int) $announcement->getKey()))->handle(
        app(AnnouncementAudience::class),
        app(DispatchNotification::class),
    );

    expect(countTold($announcement))->toBe(3);
});

it('resumes at the student it stopped at rather than starting over', function (): void {
    $announcement = Announcement::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'author_user_id' => $this->teacher->getKey(),
    ]);

    $ids = $this->students->map(fn (mixed $s): int => (int) $s->getKey())->sort()->values();

    // Resume past the first student, as a re-dispatched link does.
    (new FanOutAnnouncementJob((int) $announcement->getKey(), $ids->first()))->handle(
        app(AnnouncementAudience::class),
        app(DispatchNotification::class),
    );

    $told = Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('source_id', $announcement->getKey())
        ->pluck('recipient_user_id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();

    expect($told)->not->toContain($ids->first())
        ->and(count($told))->toBe(2);
});

it('stops delivering the moment the notice is retracted', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', [
        'body' => 'تراجعنا عن هذا.',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();
    $this->deleteJson("/api/v1/manage/announcements/{$uuid}")->assertOk();

    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    // The rows are gone AND a re-dispatched link writes none back: the job
    // re-reads the announcement at the top of every chunk, so a hide lands
    // within one pass rather than at the end of the walk.
    expect(countTold($announcement))->toBe(0);

    // Dropped on purpose: a queue worker holds no request's workspace, and the
    // job must read the announcement without one. It does — `withoutGlobalScopes()`
    // — and this is what would fail if that were ever relaxed to a scoped read.
    app(WorkspaceContext::class)->forget();

    (new FanOutAnnouncementJob((int) $announcement->getKey()))->handle(
        app(AnnouncementAudience::class),
        app(DispatchNotification::class),
    );

    expect(countTold($announcement))->toBe(0);
});

it('carries an edit to everyone already holding it', function (): void {
    /*
    | `FR-047`. ⚠️ AND THE RECIPIENT'S COPY IS WHAT IS ASSERTED, NOT THE ROW THE
    | TEACHER EDITED. For an announcement the notification IS the delivery —
    | there is no student announcements screen — so an edit that touched only
    | `announcements` would change what the publisher sees and nothing at all of
    | what the class reads: the correction visible to the one person who did not
    | need it. Mistype the `source_type` in that update and every other test in
    | this phase stays green.
    */
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', [
        'body' => 'BEFORE_SENTINEL',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    $this->patchJson("/api/v1/manage/announcements/{$uuid}", [
        'body' => 'AFTER_SENTINEL',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertOk();

    $bodies = Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->pluck('body')
        ->all();

    // ASCII sentinels, for the reason every exposure test in this product uses
    // them: an Arabic needle is unicode-escaped in a JSON payload and vacuously
    // absent. Here the read is direct, and the discipline is kept anyway.
    expect($bodies)->toHaveCount(3)
        ->and(implode('|', $bodies))->not->toContain('BEFORE_SENTINEL');

    foreach ($bodies as $body) {
        expect($body)->toContain('AFTER_SENTINEL');
    }

    // The other half of FR-047: it is recorded.
    expect(DB::table('activity_log')->where('description', 'announcement.updated')->count())->toBe(1);
});

it('leaves a retracted notice retracted when it is edited', function (): void {
    Sanctum::actingAs($this->teacher);

    $uuid = $this->postJson('/api/v1/manage/announcements', [
        'body' => 'BEFORE_SENTINEL',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();
    $this->deleteJson("/api/v1/manage/announcements/{$uuid}")->assertOk();

    $this->patchJson("/api/v1/manage/announcements/{$uuid}", [
        'body' => 'AFTER_SENTINEL',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertOk();

    // An edit must not resurrect the delivery. `isLive()` is false, so nothing
    // is rewritten and nothing is written back.
    $announcement = Announcement::query()->where('uuid', $uuid)->firstOrFail();

    expect(countTold($announcement))->toBe(0);
});
