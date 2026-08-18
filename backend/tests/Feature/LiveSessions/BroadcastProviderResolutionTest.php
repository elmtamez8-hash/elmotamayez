<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Modules\Marketplace\Models\TeacherProfile;
use Tests\Support\FakeBroadcastProvider;

/*
| A SESSION'S ROOM BELONGS TO THE PROVIDER THAT OPENED IT, NOT TO TODAY'S CONFIG.
|
| ⚠️ LITERALLY THE DEFECT 019 FIXED FOR `media_assets.provider`, ONE MODULE OVER.
| `class_sessions.broadcast_provider` was written when the room opened and read by
| NOTHING — every consumer took the single binding from the config. Harmless with
| one provider; a silent loss the day there are two.
|
| The cost is a wage and a file. Flip the platform to a provider that does not
| record while yesterday's recordings are still in flight: the ingest job asks the
| CONFIGURED provider whether it records, is told no, and returns having written
| nothing. The egress file sits in our own bucket with nobody left to ask for it,
| `PackageCompletion` releases the teacher's fee against it, and the seat holders
| are never told — that notification lives below the early return.
|
| ⚠️ TWO PROVIDERS IN ONE DATABASE, WHICH IS THE ONLY SHAPE THAT SEES IT. A single
| session can never fail this way: a test's config always agrees with its own
| fixture's column. That is the same reason SC-011 needs two assets.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

function sessionOn(?string $provider): ClassSession
{
    $session = ClassSession::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
    ]);

    $session->forceFill(['broadcast_provider' => $provider])->save();

    return $session;
}

it('answers with the provider each session was opened on', function (): void {
    // The platform now runs the recording-capable one.
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    $current = sessionOn('fake');
    $older = sessionOn('null');

    $resolver = app(BroadcastProviderResolver::class);

    expect($resolver->for($current))->toBeInstanceOf(FakeBroadcastProvider::class)
        // ⚠️ THE HALF THAT WAS BROKEN: yesterday's session still answers to
        // yesterday's provider, whatever the config says today.
        ->and($resolver->for($older))->toBeInstanceOf(NullBroadcastProvider::class);
});

// A room that was never opened has no column to read, so the configured provider
// is the only answer there is — the same split MediaProviderResolver::forKind()
// exists for, and the reason OpenBroadcastRoom keeps the binding.
it('falls back to the configured provider for a session with no room yet', function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    expect(app(BroadcastProviderResolver::class)->for(sessionOn(null)))
        ->toBeInstanceOf(FakeBroadcastProvider::class);
});

/*
| ⚠️ LOUDLY, NEVER QUIETLY. A session naming a provider this deployment does not
| have must throw: the alternative is asking the wrong provider about a room it
| never created and being told, correctly, that there is nothing there — a lost
| recording reported as an absent one.
*/
it('refuses a provider this deployment does not have', function (): void {
    expect(fn () => app(BroadcastProviderResolver::class)->for(sessionOn('a-provider-that-was-removed')))
        ->toThrow(RuntimeException::class);
});

// What the sweep filters on. It cannot ask per row inside a query, so it asks for
// the names once — and the answer has to include whatever is actually BOUND, which
// a static map cannot know about.
it('names the providers that record, including the bound one', function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    $names = app(BroadcastProviderResolver::class)->recordingProviderNames();

    expect($names)->toContain('fake')
        ->and($names)->not->toContain('null');
});
