<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ A PAID SUBSCRIPTION MUST REACH ITS PLAN FOR A STUDENT STAMPED ELSEWHERE.
|
| `Subscription::plan()` ran under `WorkspaceScope`, and `WorkspaceContext::id()`
| falls back to `users.last_workspace_id` — stamped on every student a teacher, an
| invitation or a seeder ever added to a workspace. A student added to teacher A
| who then subscribed with teacher B read the plan through A's workspace: null.
| So `SubscriptionEligibility::reaches()` answered «no plan» and the paid month
| opened nothing, and `/billing/subscriptions` showed `plan_title: null`.
|
| BOTH shapes, because the null-context student is the one every other fixture
| builds and the scope is inert for them — a file with only that shape is green
| over the defect. `last_workspace_id` is in `User::$guarded`, so it is stamped
| with `forceFill()`; `create([...])` would drop it in silence.
|
| How it bites: remove `->withoutGlobalScope(WorkspaceScope::class)` from
| `Subscription::plan()` ⇒ the two «stamped elsewhere» cases fail.
*/

beforeEach(function (): void {
    [$this->workspaceA] = $this->createWorkspaceWithOwner();
    [$this->workspaceB] = $this->createWorkspaceWithOwner();

    $this->course = courseWithRate((int) $this->workspaceB->getKey());

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspaceB->getKey(),
        'title' => 'PLAN-SENTINEL',
    ]);

    $this->student = User::factory()->create();

    Subscription::factory()->create([
        'plan_id' => $this->plan->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

function stampedSubscriberActAs(User $student, ?int $stampedWorkspaceId): void
{
    if ($stampedWorkspaceId !== null) {
        $student->forceFill(['last_workspace_id' => $stampedWorkspaceId])->save();
    }

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    // Positive control: the fixture really is the person it claims to be.
    expect(app(WorkspaceContext::class)->id())->toBe($stampedWorkspaceId);
}

dataset('subscriber shapes', [
    'null context' => [false],
    'stamped elsewhere' => [true],
]);

it('names the plan on the student\'s subscription list', function (bool $stamped): void {
    stampedSubscriberActAs($this->student, $stamped ? (int) $this->workspaceA->getKey() : null);

    $this->getJson('/api/v1/billing/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.plan_title', 'PLAN-SENTINEL');
})->with('subscriber shapes');

it('lets the paid subscription open the course it covers', function (bool $stamped): void {
    stampedSubscriberActAs($this->student, $stamped ? (int) $this->workspaceA->getKey() : null);

    expect(app(SubscriptionEligibility::class)->coversCourse(
        (int) $this->student->getKey(),
        (int) $this->course->getKey(),
    ))->toBeTrue();
})->with('subscriber shapes');
