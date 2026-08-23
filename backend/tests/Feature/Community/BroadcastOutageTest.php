<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;

/*
| SC-015 · NFR-013 — the database is the source and the socket is an accelerator.
| The real-time service falling over must not stop a message being written, read
| back, or found on the next page load.
|
| ⚠️ IT DISABLES THE SERVICE FOR REAL, RATHER THAN DECLINING TO START IT. The
| default test broadcaster is `null`, whose `broadcast()` is an empty method — a
| test that merely leaves it alone proves that a driver which does nothing breaks
| nothing, which is true of any code at all. So this file registers a driver that
| THROWS on every publish, and asserts it was actually reached: a green run with
| zero invocations is the vacuous pass this task exists to forbid.
|
| ⚠️ AND ON THE `sync` QUEUE THE THROW LANDS INSIDE THE REQUEST. `ShouldBroadcast`
| pushes a `BroadcastEvent` job, which on `sync` executes inline — so the
| exception propagates up through `event()` and into `PostMessage` unless that
| Action catches it. In production the push and the publish are a worker apart and
| a dead reverb fails the JOB rather than the request; the catch is what covers
| the remaining case (the queue itself unreachable at dispatch time) and what
| makes this criterion measurable at all.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    Sanctum::actingAs($this->student);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');
});

it('saves and returns a message while the broadcast service is throwing', function (): void {
    // A counter the driver increments, so «the service was actually exercised»
    // is an assertion rather than an assumption.
    $reached = new class
    {
        public int $publishes = 0;
    };

    Broadcast::extend('exploding', fn (): Broadcaster => new class($reached) extends Broadcaster
    {
        public function __construct(private readonly object $spy) {}

        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            $this->spy->publishes++;

            throw new RuntimeException('reverb is down');
        }
    });

    /*
    | ⚠️ THE CONNECTION ENTRY IS NOT OPTIONAL. `BroadcastEvent` resolves the driver
    | through `BroadcastManager::getConfig()`, which throws «connection is not
    | defined» for a name with no config — and that exception is caught by
    | `PostMessage` exactly like a publish failure would be, leaving the counter at
    | zero and the test measuring a driver it never reached.
    */
    config([
        'broadcasting.default' => 'exploding',
        'broadcasting.connections.exploding' => ['driver' => 'exploding'],
    ]);

    /*
    | ⚠️ AND THE MANAGER IS NOT RESET AFTERWARDS, WHICH IT ONCE WAS. `extend()`
    | stores the creator on the MANAGER INSTANCE, so forgetting that singleton
    | throws the exploding driver away — the resolve then fails for a third reason
    | («driver not supported»), gets caught by the Action like any publish failure,
    | and the counter sits at zero while every other assertion passes.
    */

    Sanctum::actingAs($this->student);

    $response = $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
        'body' => 'هل الحصّة غداً؟',
    ])->assertCreated();

    expect($response->json('body'))->toBe('هل الحصّة غداً؟')
        // The driver ran and refused. Without this the whole file would pass
        // against a build that never attempted to broadcast anything.
        ->and($reached->publishes)->toBeGreaterThan(0);

    // Read back through the shipped endpoint, still with the broken driver bound.
    $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.body', 'هل الحصّة غداً؟');

    // And the rest of the platform is untouched.
    $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1);
});
