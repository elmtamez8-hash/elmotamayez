<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\StaffAccounts;
use App\Shared\Actions\Action;
use Illuminate\Support\Str;

class InviteMember extends Action
{
    public function handle(Workspace $workspace, string $email, string $role, ?User $inviter = null): Invitation
    {
        /*
        | ⛔ حسابُ الطالبِ أو وليِّ الأمرِ لا يصيرُ عضواً في الفريق (قرارُ المالك
        | 2026-09-26). يُرفَضُ هنا قبلَ أن تُرسَلَ الدعوة، لا عندَ قبولِها بعدَ أن
        | انتظرَها صاحبُها — {@see StaffAccounts}.
        */
        StaffAccounts::guard(StaffAccounts::accountFor($email), $role);

        return Invitation::create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
