<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10 — F2 · F20.
*/

it('never lets an owner write the platform-only billing mode through the workspace update', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $workspace->forceFill(['settings' => ['billing' => ['mode' => 'prepaid'], 'inbox' => ['accepts_prospects' => true]]])->save();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", [
        'name' => 'اسم جديد',
        'settings' => ['billing' => ['mode' => 'manual_collection', 'zero_balance_behavior' => 'remind']],
    ])->assertOk();

    $fresh = $workspace->fresh();

    // The name moved; the settings — billing and everything beside it — did not.
    expect($fresh->name)->toBe('اسم جديد')
        ->and($fresh->settings)->toBe(['billing' => ['mode' => 'prepaid'], 'inbox' => ['accepts_prospects' => true]]);
});

it('refuses the workspace update to an owner whose membership has ended', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'قبل']);
    $this->setCurrentWorkspace($workspace, $owner);

    // What a completed exit leaves behind: `owner_user_id` kept, membership gone.
    $workspace->members()->detach($owner->getKey());
    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'بعد'])->assertForbidden();

    expect($workspace->fresh()->name)->toBe('قبل');
});
