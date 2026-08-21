<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Actions\ExecuteDataErasure;
use App\Modules\Compliance\Actions\PlaceLegalHold;
use App\Modules\Compliance\Actions\ReleaseLegalHold;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Exceptions\LegalHoldInForce;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;

/**
 * SC-009 — a hold stops an erasure, through all THREE of its doors.
 *
 * ⚠️ THE THIRD DOOR IS THE ONE READERS MISS, and it is the only one that cannot be
 * added later. A hold placed before the request and a hold placed before the sweep
 * are both checks at a boundary; a hold placed WHILE THE WALK IS RUNNING has no
 * boundary to sit on, because the walk is minutes long and each batch is already
 * irreversible by the time the next one starts. `ExecuteDataErasure` re-reads the
 * table at the head of every batch for exactly that reason, and a test that only
 * covered the first two would pass against a version that checked once at the top.
 */
beforeEach(function (): void {
    Storage::fake('local');

    [$workspace] = $this->createWorkspaceWithOwner();

    $this->workspace = $workspace;
    $this->officer = User::factory()->create();
    $this->subject = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
        Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => Course::factory()->create([
                'workspace_id' => $workspace->getKey(),
            ])->getKey(),
            'student_user_id' => $this->subject->getKey(),
            'status' => 'active',
        ]);
    });
});

it('suspends an open request the moment a hold is placed', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'أمر قضائي');

    expect($request->refresh()->status)->toBe(DataRequestStatus::OnHold);
});

it('refuses to erase while a hold stands', function (): void {
    app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'تحقيق جارٍ');

    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    expect(fn () => app(ExecuteDataErasure::class)->handle($request))
        ->toThrow(LegalHoldInForce::class);

    // And nothing was destroyed on the way to the refusal.
    expect(Enrollment::query()->withoutWorkspaceScope()->where('student_user_id', $this->subject->getKey())->count())
        ->toBe(1);
});

/*
 * ⚠️ THE THIRD DOOR, MEASURED. The hold is placed by a module's own `erase()` —
 * which is to say, from INSIDE the walk, between two batches — and that is exactly
 * where a real one lands: a court order arriving while a job is running. No threads
 * and no sleeps; the callback IS the other actor, in the one window that matters.
 */
it('stops mid-walk when a hold arrives between batches', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    $officer = $this->officer;
    $subject = $this->subject;

    // A hold placed the instant the first module reports back.
    Enrollment::query()->withoutWorkspaceScope()->getConnection()->listen(
        function ($query) use ($officer, $subject): void {
            if (str_contains($query->sql, 'delete from "enrollments"')
                && ! LegalHold::heldFor((int) $subject->getKey())) {
                app(PlaceLegalHold::class)->handle($subject, $officer, 'أمر وصل أثناء المحو');
            }
        },
    );

    expect(fn () => app(ExecuteDataErasure::class)->handle($request))
        ->toThrow(LegalHoldInForce::class);
});

it('lets the request proceed once the hold is released', function (): void {
    /*
    | ⚠️ THE REQUEST IS OPENED FIRST, AND THE ORDER MATTERS TO WHAT IS BEING TESTED.
    | A request opened WHILE a hold stands is `pending`, deliberately: the right to
    | ASK is not suspended by a hold — the right to have it EXECUTED is, and the job
    | is what refuses. `PlaceLegalHold` suspends the requests that are already open,
    | which is this one.
    */
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    $hold = app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'مؤقّت');

    expect($request->refresh()->status)->toBe(DataRequestStatus::OnHold);

    app(ReleaseLegalHold::class)->handle($hold, $this->officer);

    /*
    | ⚠️ BACK TO `pending`, NOT STRAIGHT TO RUNNING. Lifting a hold says the reason
    | for it has gone, not that an irreversible destruction should now proceed
    | unattended — the officer's execute endpoint is what runs it, and what writes
    | `executed_by_user_id`.
    */
    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed);
});

/*
 * ⚠️ A HOLD STOPS A DESTRUCTION AND NOTHING ELSE — THE SUBJECT'S EXPORT RUNS ON.
 *
 * Wrong on the merits first: a hold says the data must STAY, which has nothing to
 * say about a person's right to read what is held about them. And the mechanism
 * made it permanent — suspending an export moves it out of `pending`, `store`'s one
 * dispatch has already fired, the stalled sweep reads `processing` alone, and the
 * officer's execute endpoint is for erasures. It sat past `due_at` with nothing left
 * anywhere to move it.
 *
 * ⚠️ IT WAS INVISIBLE BECAUSE EVERY OTHER REQUEST IN THIS FILE IS AN ERASURE. A
 * suite whose fixtures are all one type cannot see a predicate that forgot to name
 * a type.
 */
it('leaves the subject s export alone when a hold is placed', function (): void {
    $export = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Export,
    );

    app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'أمر قضائي');

    expect($export->refresh()->status)->toBe(DataRequestStatus::Pending);

    FulfilDataRequestJob::dispatchSync((int) $export->getKey());

    expect($export->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and($export->export_path)->not->toBeNull();
});

/*
 * ⚠️ TWO HOLDS, ONE RELEASE — AND THE REQUEST STAYS SUSPENDED.
 *
 * Two courts, two orders, one lifted. Resuming on the first release proceeds
 * against an order still in force, which is the single mistake in this file that
 * cannot be undone afterwards.
 */
it('keeps the request suspended while another hold stands', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    $first = app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'أوّل');
    app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'ثانٍ');

    app(ReleaseLegalHold::class)->handle($first, $this->officer);

    expect($request->refresh()->status)->toBe(DataRequestStatus::OnHold);
});

/*
 * A hold met by the JOB writes `on_hold` rather than leaving the request in
 * `processing`, where the stalled sweep would revive it every thirty minutes and
 * meet the same hold — an unbounded loop over a court order.
 */
it('parks a held request out of the sweep s reach', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->subject,
        (string) $this->subject->uuid,
        DataRequestType::Erasure,
    );

    /*
    | The hold is placed after creation — which suspends the request — and the
    | status is then put back so that the JOB is what meets the hold, rather than
    | the creation path. That is the case a real deployment produces: a hold
    | arriving in the minutes between an officer pressing execute and a worker
    | picking the job up.
    |
    | ⚠️ WRITTEN BY QUERY, NOT BY `forceFill` ON `$request`. `PlaceLegalHold` uses a
    | mass update, so the in-memory model still says `pending` — `forceFill` to the
    | same value marks nothing dirty and `save()` writes nothing at all. The row
    | stays `on_hold`, the job's claim matches zero rows, and the test passes its
    | status assertion for entirely the wrong reason.
    */
    app(PlaceLegalHold::class)->handle($this->subject, $this->officer, 'وصل متأخّراً');

    DataRequest::query()
        ->whereKey($request->getKey())
        ->update(['status' => DataRequestStatus::Pending->value]);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect($request->refresh()->status)->toBe(DataRequestStatus::OnHold)
        ->and($request->refusal_reason)->not->toBeNull();
});
