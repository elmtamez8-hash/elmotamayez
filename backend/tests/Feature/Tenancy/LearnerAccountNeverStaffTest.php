<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Tenancy\Support\StaffAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ حسابُ الطالبِ أو وليِّ الأمرِ لا يصيرُ عضواً في فريقِ العمل — قرارُ المالك
| 2026-09-26. ثلاثةُ أبوابٍ تكتبُ صفَّ موظّف، وكلُّها تُسأَل: الدعوة، وقبولُها،
| وترقيةُ صفِّ `student`. والحسابُ الذي لم يُعلِنْ دورَه (`platform_role` فارغ)
| يمرّ — القاعدةُ عن حسابٍ قالَ إنّه طالبٌ أو وليُّ أمر.
*/

/** A pending invitation written directly — the rule may postdate it. */
function pendingInvitation(mixed $workspace, string $email, string $role): Invitation
{
    return Invitation::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->getKey(),
        'email' => $email,
        'role' => $role,
        'token' => Str::random(64),
        'expires_at' => now()->addDays(7),
    ]);
}

describe('InviteMember', function (): void {
    it('refuses a staff invitation to a student or parent account', function (PlatformRole $role): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        User::factory()->create(['email' => 'learner@example.test', 'platform_role' => $role]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            // A different case than the stored address: SQLite compares case-sensitively.
            'email' => 'Learner@Example.test',
            'role' => Roles::ASSISTANT_TEACHER,
        ])->assertStatus(422)->assertJsonPath('message', StaffAccounts::REFUSAL);

        expect(Invitation::query()->withoutWorkspaceScope()->count())->toBe(0);
    })->with([PlatformRole::Student, PlatformRole::Parent]);

    it('still invites a student account AS a student', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        User::factory()->create(['email' => 'learner@example.test', 'platform_role' => PlatformRole::Student]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'learner@example.test',
            'role' => Roles::STUDENT,
        ])->assertCreated();
    });

    it('invites a teacher account, and an address with no account yet, as staff', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        User::factory()->create(['email' => 'colleague@example.test', 'platform_role' => PlatformRole::Teacher]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'colleague@example.test',
            'role' => Roles::TEACHER,
        ])->assertCreated();

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'nobody-yet@example.test',
            'role' => Roles::ASSISTANT_TEACHER,
        ])->assertCreated();
    });
});

describe('AcceptInvitation', function (): void {
    it('refuses a student account accepting a staff invitation, and writes nothing', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        // Sent before the address registered as a student — the door that
        // writes the row asks again.
        $invitation = pendingInvitation($workspace, 'learner@example.test', Roles::TEACHER);
        $learner = User::factory()->create(['email' => 'learner@example.test', 'platform_role' => PlatformRole::Student]);

        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/workspaces/invitations/{$invitation->token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', StaffAccounts::REFUSAL);

        expect(DB::table('workspace_members')->where('user_id', $learner->getKey())->exists())->toBeFalse()
            ->and(DB::table('model_has_roles')->where('model_id', $learner->getKey())->exists())->toBeFalse()
            ->and($invitation->fresh()?->accepted_at)->toBeNull();
    });

    it('lets a student account accept a STUDENT invitation', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $invitation = pendingInvitation($workspace, 'learner@example.test', Roles::STUDENT);
        $learner = User::factory()->create(['email' => 'learner@example.test', 'platform_role' => PlatformRole::Student]);

        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/workspaces/invitations/{$invitation->token}/accept")->assertOk();

        expect(DB::table('workspace_members')
            ->where('user_id', $learner->getKey())
            ->value('role'))->toBe(Roles::STUDENT);
    });
});

describe('UpdateWorkspaceMemberRole', function (): void {
    it('refuses promoting a student account\'s row to staff', function (string $to): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $learner = $this->addWorkspaceMember(
            $workspace,
            Roles::STUDENT,
            User::factory()->create(['platform_role' => PlatformRole::Student]),
        );
        $this->setCurrentWorkspace($workspace, $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$learner->uuid}", ['role' => $to])
            ->assertStatus(422)
            ->assertJsonPath('message', StaffAccounts::REFUSAL);

        expect(DB::table('workspace_members')
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $learner->getKey())
            ->value('role'))->toBe(Roles::STUDENT)
            ->and(DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $learner->getKey())
                ->where('model_has_roles.team_id', $workspace->getKey())
                ->pluck('roles.name')
                ->all())->toBe([Roles::STUDENT]);
    })->with([Roles::ASSISTANT_TEACHER, Roles::TEACHER]);

    it('still promotes a member whose account declared no learner role', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $member = $this->addWorkspaceMember($workspace, Roles::STUDENT);
        $this->setCurrentWorkspace($workspace, $owner);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$member->uuid}", [
            'role' => Roles::ASSISTANT_TEACHER,
        ])->assertNoContent();
    });
});
