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
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · US2 · T067 — FR-025: A FROZEN CREDIT CANNOT BE SPENT ON SOMETHING ELSE.
|
| ⛔ TWO CASES OR THE `− held` HALF OF THE FLOOR IS NOT MEASURED AT ALL. With one
| credit owned and one frozen against next Tuesday's seat, opening today's
| content must be refused — and with the same one credit owned and nothing
| frozen, it must succeed. A file carrying only the refusal passes against a
| build that refuses EVERY unlock, which is the mirror defect and the worse one:
| it locks a student out of a course they have paid for, and this repository has
| written that family down six times from six directions.
|
| ⚠️ AND THE REFUSAL CARRIES THE ROAD OUT. `purchase_url` travels on the offer in
| the 422 body, always — a refusal a student can do nothing with is the shape
| FR-013 forbids in as many words.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 2);

    // The hour's material: with nothing to open there is no offer, and both
    // cases would answer 403 for a reason that is not the floor.
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

    // Excused before the room closed: the seat is exempt, so the content is
    // locked and buying it is possible at all (FR-011 forbids a second charge on
    // an hour already open).
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, []);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);
});

/** Put the balance exactly where the case needs it, held counter included. */
function floorBalanceAt(object $test, int $remaining, int $held): void
{
    DB::table('credit_balances')
        ->where('id', $test->balance->getKey())
        ->update(['remaining_credits' => $remaining, 'held_credits' => $held]);
}

it('refuses the last credit when it is frozen for another seat', function (): void {
    floorBalanceAt($this, remaining: 1, held: 1);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/unlock")
        ->assertStatus(422)
        ->assertJsonPath('offer.available_credits', 0)
        // ⚠️ THE WAY OUT, IN THE SAME BODY AS THE REFUSAL.
        ->assertJsonPath('offer.purchase_url', '/billing/purchase');

    // Nothing opened and nothing was spent: a refusal that had already written
    // the unlock row would be the worst of both.
    expect(SessionUnlock::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and((int) DB::table('credit_balances')->where('id', $this->balance->getKey())->value('remaining_credits'))->toBe(1);
});

it('spends the last credit when nothing is frozen', function (): void {
    floorBalanceAt($this, remaining: 1, held: 0);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/unlock")
        ->assertOk()
        ->assertJsonPath('content_locked', false);

    expect(SessionUnlock::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and((int) DB::table('credit_balances')->where('id', $this->balance->getKey())->value('remaining_credits'))->toBe(0);
});
