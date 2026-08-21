<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use Illuminate\Support\Facades\Storage;

/**
 * Two taps in one second produce ONE request, and two workers ONE archive.
 *
 * ⚠️ NEITHER GUARD IS A THROTTLE, AND A THROTTLE COULD NOT BE ONE. A per-minute
 * limiter does not close a race measured in milliseconds; "read, then insert" and
 * "read, then update" both let two callers past. Both guards here are the same
 * idiom this repository uses for seats, for `captured_order_id` and for
 * `StructureVersion::claim()` — a UNIQUE column and a conditional UPDATE, where the
 * check and the claim are one statement.
 *
 * ⚠️ AND THE COST OF LOSING EITHER ONE IS THE SAME OBJECT TWICE: two archives of
 * everything the platform knows about a minor, each with its own signed link and
 * its own expiry, one of which nothing will ever clean up because nothing knows it
 * exists.
 */
beforeEach(function (): void {
    Storage::fake('local');

    $this->subject = User::factory()->create();
});

it('opens one request for two calls in the same second', function (): void {
    $first = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);
    $second = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    expect(DataRequest::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey());
});

/*
 * ⚠️ AND THE LOCK IS RELEASED ON CLOSE, or the person may never ask again.
 *
 * `open_key` is nulled inside the statement that completes the request, and NULL
 * never collides with NULL — which is the whole reason it is a nullable unique
 * column and not a partial index (MySQL has none).
 */
it('lets the person ask again once the first request has closed', function (): void {
    $first = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $first->getKey());

    expect($first->refresh()->open_key)->toBeNull();

    $second = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(DataRequest::query()->count())->toBe(2);
});

/*
 * ⚠️ AN EXPORT AND AN ERASURE ARE DIFFERENT LOCKS.
 *
 * The key is `{subject}:{type}`, so a person with an export in flight can still ask
 * to be erased. A key on the subject alone would make the second right unreachable
 * while the first was pending, for as long as the first took.
 */
it('locks per type, not per person', function (): void {
    app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);
    app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Erasure);

    expect(DataRequest::query()->count())->toBe(2);
});

/*
 * ⚠️ THE SECOND RUNNER PRODUCES NOTHING, and this is the assertion that fails if
 * the claim is ever rewritten as a read followed by a write.
 *
 * `RetryStalledDataRequestsJob` re-dispatches every ten minutes, so two runners
 * meeting on one request is ordinary rather than exotic. The second call here
 * arrives at a request already `completed`, matches zero rows on
 * `WHERE status = 'pending'`, and returns — leaving the first archive untouched
 * rather than generating and signing a second one.
 */
it('runs one request once, however many workers pick it up', function (): void {
    $request = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $firstPath = $request->refresh()->export_path;
    $firstCompletedAt = $request->completed_at;

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect($request->refresh()->export_path)->toBe($firstPath)
        ->and($request->completed_at?->toIso8601String())->toBe($firstCompletedAt?->toIso8601String())
        ->and($request->status)->toBe(DataRequestStatus::Completed);
});
