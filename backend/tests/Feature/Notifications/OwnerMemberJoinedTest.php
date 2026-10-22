<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| The owner learns that their invitation was accepted (co-teacher journey
| 2026-10-22) — before, only by opening «فريقك» again.
*/

beforeEach(function (): void {
    (new NotificationTemplateSeeder)->run();
});

function joinedInvitation(mixed $workspace, string $email, string $role): Invitation
{
    $invitation = Invitation::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->getKey(),
        'email' => $email,
        'role' => $role,
        'token' => Invitation::hashToken($plain = Str::random(64)),
        'expires_at' => now()->addDays(7),
    ]);
    $invitation->plainToken = $plain;

    return $invitation;
}

it('tells the owner who joined and as what', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $omar = User::factory()->create(['first_name' => 'عمر', 'last_name' => 'الشريك', 'platform_role' => 'teacher']);
    $invitation = joinedInvitation($workspace, $omar->email, Roles::TEACHER);

    Sanctum::actingAs($omar);
    $this->postJson("/api/v1/workspaces/invitations/{$invitation->plainToken}/accept")->assertOk();

    $row = DB::table('notifications')
        ->where('recipient_user_id', $owner->getKey())
        ->where('type', NotificationType::WorkspaceMemberJoined->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->title, true)['ar'])->toContain('عمر الشريك')
        ->and(json_decode((string) $row->body, true)['ar'])->toContain('مدرّس')
        ->and($row->action_url)->toBe('/members');
});
