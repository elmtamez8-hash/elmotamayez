<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Jobs\RunRetentionSweepJob;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Models\RetentionSweepRun;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Support\WorkspaceContext;

/**
 * SC-010 — the sweep is idempotent, and the invariant is a TRIPLE.
 *
 * ⚠️ "TWO RUNS LEAVE THE SAME STATE" IS FALSE OF ONE OF THE TABLES INVOLVED, and
 * asserting it there would fail a correct implementation. `retention_sweep_runs`
 * is append-only by nature: two executions MUST write two rows, or a log whose
 * only purpose is answering "when did this last run" cannot answer it. The
 * correct triple is:
 *
 *   1. identical DATA rows after the second pass,
 *   2. exactly ONE run row per execution,
 *   3. zero destructive effect on the second pass.
 *
 * ⚠️ AND THE FIXTURE INCLUDES A CATEGORY WHOSE BEHAVIOUR IS `archive`, because
 * that is the behaviour whose obvious implementation never converges: its
 * predicate is "older than N days", true again tomorrow, so without a mark the
 * same rows are re-archived — and the same file re-deleted at the provider —
 * every night for ever, each time counted afresh in the run log. `delete` cannot
 * show that defect: a deleted row does not come back.
 */
beforeEach(function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $this->workspace = $workspace;
    $this->student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
        $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

        $enrollment = Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $this->student->getKey(),
            'status' => 'active',
        ]);

        $lesson = $course->lessons()->first() ?? Lesson::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
        ]);

        // Four years old: past `lesson_progress`'s 1095 days.
        LessonProgress::query()->create([
            'workspace_id' => $workspace->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'lesson_id' => $lesson->getKey(),
            'status' => 'completed',
        ]);
        LessonProgress::query()->withoutWorkspaceScope()->update(['created_at' => now()->subDays(1500)]);

        $session = ClassSession::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'status' => 'completed',
        ]);

        $this->session = $session;

        // Past `attendance_record`'s 1095 days, and carrying the free text the
        // anonymise behaviour clears.
        Attendance::query()->create([
            'workspace_id' => $workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $this->student->getKey(),
            'status' => 'present',
            'source' => 'manual',
            'override_reason' => 'وصل متأخّراً لظرفٍ عائليّ.',
        ]);

        /*
        | ⚠️ AGED BY QUERY, NEVER INSIDE THE `create()` ARRAY. `created_at` is not
        | fillable, so mass assignment DISCARDS it in silence and the row is born
        | today — after which every assertion in this file passes against a fixture
        | too young to be swept, proving the exact opposite of what it claims. It
        | is how the first draft of this test read, and it was green.
        */
        Attendance::query()->withoutWorkspaceScope()->update(['created_at' => now()->subDays(1500)]);

        // Past `class_recording`'s 730 days — the ARCHIVE category.
        $this->asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'owner_type' => ClassSession::class,
            'owner_id' => $session->getKey(),
            'provider' => 'local',
            'provider_asset_id' => 'vid-1',
            'kind' => MediaKind::Video,
            'role' => MediaRole::Primary,
            'status' => 'ready',
            'original_filename' => 'session.mp4',
        ]);
        MediaAsset::query()->withoutWorkspaceScope()->update(['created_at' => now()->subDays(900)]);
    });

    // Past `notification_record`'s 180 days, and READ — an unread row is never
    // swept, whatever its age.
    Notification::query()->create([
        'recipient_user_id' => $this->student->getKey(),
        'workspace_id' => $workspace->getKey(),
        'type' => 'session_reminder',
        'title_ar' => 'تذكير',
        'body_ar' => 'حصّتك غداً.',
        'read_at' => now()->subDays(400),
    ]);

    /*
    | ⚠️ AND ONE JUST AS OLD THAT NOBODY HAS OPENED. An unread row is a message its
    | recipient has not seen yet, so deleting it turns a delivered notification
    | into one that silently never arrived — worse than an old feed. The condition
    | crossed over with the body of `PruneOldNotificationsJob`, and a fixture of
    | read rows alone cannot notice it being dropped.
    */
    Notification::query()->create([
        'recipient_user_id' => $this->student->getKey(),
        'workspace_id' => $workspace->getKey(),
        'type' => 'session_reminder',
        'title_ar' => 'تذكير لم يُقرأ',
        'body_ar' => 'حصّتك غداً.',
    ]);

    // Aged by query — see the note in the workspace block above.
    Notification::query()->update(['created_at' => now()->subDays(400)]);

    $this->deletes = 0;

    /*
    | ⚠️ MOCKED ON THE CONCRETE CLASS, NOT ON `MediaProviderInterface`. The
    | resolver reads the asset's own `provider` COLUMN and makes that class — it
    | never asks the container for the interface — so a mock bound to the
    | interface is never consulted, the real local provider runs, and the "no
    | second deletion" assertion passes by counting zero of both.
    */
    $this->mock(LocalMediaProvider::class, function ($mock): void {
        $mock->shouldReceive('identifier')->andReturn('local');
        $mock->shouldReceive('delete')->andReturnUsing(function (): void {
            $this->deletes++;
        });
    });
});

it('leaves the same state behind on a second run, and logs both', function (): void {
    RunRetentionSweepJob::dispatchSync();

    $afterFirst = [
        'progress' => LessonProgress::query()->withoutWorkspaceScope()->count(),
        'notifications' => Notification::query()->count(),
        'reasons' => Attendance::query()->withoutWorkspaceScope()->whereNotNull('override_reason')->count(),
        'archived' => MediaAsset::query()->withoutWorkspaceScope()->whereNotNull('archived_at')->count(),
    ];
    $deletesAfterFirst = $this->deletes;

    // Everything that should have gone, went.
    // The unread one survives; only the read one goes.
    expect($afterFirst)->toBe(['progress' => 0, 'notifications' => 1, 'reasons' => 0, 'archived' => 1]);

    RunRetentionSweepJob::dispatchSync();

    // 1 — the data is identical.
    expect([
        'progress' => LessonProgress::query()->withoutWorkspaceScope()->count(),
        'notifications' => Notification::query()->count(),
        'reasons' => Attendance::query()->withoutWorkspaceScope()->whereNotNull('override_reason')->count(),
        'archived' => MediaAsset::query()->withoutWorkspaceScope()->whereNotNull('archived_at')->count(),
    ])->toBe($afterFirst);

    /*
     * 3 — and the second pass did NOTHING, which the counts above cannot show
     * for the archive category: the row is already stamped, so a job that
     * re-archived it would leave the same count while calling the provider a
     * second time and paying for the deletion twice.
     */
    expect($this->deletes)->toBe($deletesAfterFirst)
        ->and($this->deletes)->toBe(1);

    // 2 — but the LOG is append-only: two executions, two rows.
    expect(RetentionSweepRun::query()->count())->toBe(2);

    $second = RetentionSweepRun::query()->latest('id')->firstOrFail();

    // The second run looked and found nothing, which is not the same as not
    // having looked — the categories were still walked.
    expect($second->categories_processed)->toBeGreaterThan(0)
        ->and($second->rows_deleted)->toBe(0)
        ->and($second->rows_archived)->toBe(0);
});

/*
 * ⚠️ FR-030 — A HOLD STOPS THE SWEEP, AND NOTHING ELSE IN THE PHASE COVERS THIS.
 *
 * A legal hold suspends an erasure REQUEST, which is what `PlaceLegalHold` writes
 * and `LegalHoldTest` measures. But retention needs nobody to ask: without the
 * exemption threaded down to each module the nightly job deletes the exact rows a
 * court ordered kept, on a schedule, with the hold row sitting green beside it and
 * not one line logged.
 */
it('spares a held subject s rows and sweeps everyone else s', function (): void {
    $other = User::factory()->create();

    Notification::query()->create([
        'recipient_user_id' => $other->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'type' => 'session_reminder',
        'title_ar' => 'تذكير',
        'body_ar' => 'حصّتك غداً.',
        'read_at' => now()->subDays(400),
    ]);

    // Aged by query — see the note in the workspace block above.
    Notification::query()->update(['created_at' => now()->subDays(400)]);

    LegalHold::query()->create([
        'subject_user_id' => $this->student->getKey(),
        'reason' => 'أمر قضائي',
        'placed_by_user_id' => $other->getKey(),
        'placed_at' => now(),
    ]);

    RunRetentionSweepJob::dispatchSync();

    // The held subject keeps everything of theirs — the read notification as well
    // as the unread one, where without the hold only the unread would survive.
    expect(Notification::query()->where('recipient_user_id', $this->student->getKey())->count())->toBe(2)
        ->and(LessonProgress::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and(Attendance::query()->withoutWorkspaceScope()->whereNotNull('override_reason')->count())->toBe(1)
        // …and nobody else is spared by their order.
        ->and(Notification::query()->where('recipient_user_id', $other->getKey())->count())->toBe(0);
});
