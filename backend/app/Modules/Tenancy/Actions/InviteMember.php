<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\RoleGrants;
use App\Modules\Tenancy\Support\StaffAccounts;
use App\Shared\Actions\Action;
use Illuminate\Support\Str;

class InviteMember extends Action
{
    public function __construct(private readonly RoleGrants $grants) {}

    /**
     * ⚠️ `$inviter` IS REQUIRED (audit 2026-09-30). It was nullable and read by
     * nothing; a guard that skips a null actor is the hole it was meant to close.
     */
    public function handle(Workspace $workspace, string $email, string $role, User $inviter): Invitation
    {
        $account = StaffAccounts::accountFor($email);

        /*
        | ⛔ حسابُ الطالبِ أو وليِّ الأمرِ لا يصيرُ عضواً في الفريق (قرارُ المالك
        | 2026-09-26). يُرفَضُ هنا قبلَ أن تُرسَلَ الدعوة، لا عندَ قبولِها بعدَ أن
        | انتظرَها صاحبُها — {@see StaffAccounts}.
        */
        StaffAccounts::guard($account, $role);

        /*
        | ⛔ A delegated `members.invite` invites no `tenant-owner` and no role
        | above its own, and nobody re-invites themselves into a new role
        | ({@see RoleGrants}).
        */
        $this->grants->guard($workspace, $inviter, $account, $role);

        $plain = Str::random(64);

        $invitation = Invitation::create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'role' => $role,
            'token' => Invitation::hashToken($plain),
            'expires_at' => now()->addDays(7),
        ]);

        // The one moment the plain value exists — see `Invitation::$plainToken`.
        $invitation->plainToken = $plain;

        return $invitation;
    }
}
