<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ A REDEMPTION MUST NAME ITS REWARD FOR A STUDENT STAMPED ELSEWHERE.
|
| `Redemption::reward()` ran under `WorkspaceScope`; for a student whose
| `users.last_workspace_id` names another teacher, the eager load returned null
| and `/gamification/redemptions` listed what they spent their coins on as a
| blank. BOTH shapes, because the scope is inert for the null-context student.
|
| How it bites: remove the bypass from `Redemption::reward()` ⇒ the stamped case
| reads `reward.title` as null.
*/

it('names the reward on the student\'s redemptions', function (bool $stamped): void {
    [$workspaceA] = $this->createWorkspaceWithOwner();
    [$workspaceB] = $this->createWorkspaceWithOwner();

    $student = User::factory()->create();

    $reward = Reward::factory()->create([
        'workspace_id' => $workspaceB->getKey(),
        'title' => 'REWARD-SENTINEL',
    ]);

    Redemption::factory()->create([
        'workspace_id' => $workspaceB->getKey(),
        'user_id' => $student->getKey(),
        'reward_id' => $reward->getKey(),
    ]);

    if ($stamped) {
        $student->forceFill(['last_workspace_id' => $workspaceA->getKey()])->save();
    }

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);

    expect(app(WorkspaceContext::class)->id())
        ->toBe($stamped ? (int) $workspaceA->getKey() : null);

    $this->getJson('/api/v1/gamification/redemptions')
        ->assertOk()
        ->assertJsonPath('data.0.reward.title', 'REWARD-SENTINEL');
})->with([
    'null context' => [false],
    'stamped elsewhere' => [true],
]);
