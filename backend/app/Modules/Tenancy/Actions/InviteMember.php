<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use Illuminate\Support\Str;

class InviteMember extends Action
{
    public function handle(Workspace $workspace, string $email, string $role, ?User $inviter = null): Invitation
    {
        /*
        | ⛔ لا سؤالَ عن الحسابِ هنا، عن قصد (2026-09-27، بموافقةِ المالك بعدَ
        | مراجعةِ ما قبلَ الإطلاق). كانَ هذا البابُ يرفضُ بـ422 دعوةَ فريقٍ لبريدِ
        | طالبٍ أو وليِّ أمر — فصارَ كلُّ من يملكُ `members.invite` يسألُ «هل هذا
        | البريدُ طالبٌ على المنصّة؟» ويأخذُ الجوابَ، لأيِّ بريدٍ يكتبُه. الآن
        | الجوابُ واحدٌ للجميع (201 ورمز)، والرفضُ يقفُ عندَ القبولِ وحدَه، وهو
        | البابُ الذي يكتبُ الصفَّ فعلاً — {@see AcceptInvitation} و{@see
        | \App\Modules\Tenancy\Support\StaffAccounts}. الثمنُ أنّ صاحبَ البريدِ
        | يعرفُ الرفضَ حينَ يضغطُ «قبول» لا قبلَه.
        */
        return Invitation::create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
