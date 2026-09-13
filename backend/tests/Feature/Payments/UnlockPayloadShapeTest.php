<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · T044 · T046 — THE WIRE SPELLING OF THE OFFER, ON BOTH DOORS.
|
| ⛔ AND IT IS A SEPARATE MEASUREMENT FROM EVERY OTHER FILE IN THIS PHASE,
| because nothing else can see it. `SessionContentOffer` leaves this server by
| two roads — the attribute `ClassSessionResource` stamps, and the `offer` key
| of the 422 — and both go through `toArray()`, which on the BASE class is
| `get_object_vars()`, i.e. the PHP property names. The panel reads
| `available_credits`; a payload carrying `availableCredits` makes
| `undefined >= 1` false, so the button is refused to a student holding ten
| credits and the sentence «رصيدُكَ المتاحُ لا يكفي» is printed under it.
|
| Nothing fails on that build: phpstan is green (the object is correct), vitest
| is green (the fixture is hand-written in the panel's own spelling), and pest
| is green (no test asserted a key). It is the `/enrollments` envelope defect
| reached from a new direction — the server alone wrong, every reader correct.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 1);

    // The hour's own material: without it the offer is null by design — an
    // unlock that opens nothing is not something to sell.
    Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'class_session_id' => $this->session->getKey(),
        'type' => LessonType::Video,
        'status' => ContentStatus::Published,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->student, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    // Excused, delivered, unjudged — the one state in which an offer exists at
    // all: an attended seat is already open and cannot be sold twice (FR-011).
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, []);
});

it('sends the offer in the spelling the screen reads', function (): void {
    Sanctum::actingAs($this->student);

    $response = $this->getJson("/api/v1/class-sessions/{$this->session->uuid}");

    $response->assertOk()
        ->assertJsonPath('content_locked', true)
        // ⚠️ EVERY key, not one: `get_object_vars()` would have produced the
        // camel spelling of all four at once, so asserting one leaves three.
        // ⚠️ `ClassSessionResource::make()` handed to `response()->json()`
        // serialises UNWRAPPED — there is no `data` envelope on this route, and
        // the collection beside it has one.
        ->assertJsonStructure([
            'content_offer' => [
                'credits',
                'owned_credits',
                'available_credits',
                'opens',
                'available_until',
                'purchase_url',
            ],
        ]);

    // And the road out is a real path, not a null the panel would render as a
    // link to nowhere.
    expect($response->json('content_offer.purchase_url'))->toBe('/billing/purchase');
});

it('sends the same spelling in the refusal that carries the road out', function (): void {
    // Spend the balance so the door answers 422 rather than opening.
    billingBalance($this->workspace, $this->student, $this->course)
        ->forceFill(['remaining_credits' => 0])->save();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/unlock")
        ->assertStatus(422)
        ->assertJsonPath('offer.available_credits', 0)
        ->assertJsonPath('offer.purchase_url', '/billing/purchase');
});
