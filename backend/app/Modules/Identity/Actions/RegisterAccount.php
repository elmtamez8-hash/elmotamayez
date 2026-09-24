<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\RegisterAccountData;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Auth\Events\Registered;

/**
 * The invitation door: somebody a workspace invited onto its staff.
 *
 * ⚠️ IT CARRIED NO MIDDLEWARE AT ALL UNTIL 2026-08-29. `POST /auth/register` had
 * no rate limit and no idempotency key — the one unthrottled account-minting
 * endpoint on the platform — and spec 011 is the phase that would have hung
 * money on account creation (a referral reward), which is what turns an
 * unthrottled mint into a free one.
 *
 * ⛔ AND IT IS INVITATION-ONLY SINCE 2026-09-24 (owner decision). The token used
 * to be optional for one reason: an academy founder registered with nothing in
 * hand and created a workspace afterwards. Spec 025 · FR-026 abolished that path
 * (`POST /workspaces` needs a platform permission — `AcademySignupUnchangedTest`),
 * so the optionality served nobody and left only a side door: anyone typing
 * `/register` got an account with no role, no date of birth and no guardian gate,
 * and could buy courses. The token is REQUIRED here as well as in the request, so
 * a seeder or panel reaching this Action with no form behind it is refused too —
 * an empty token resolves to no invitation and is refused below.
 *
 * ⚠️ WHAT THIS DOOR IS NOT is the student signup. `RegisterStudent` is the only
 * writer that computes the guardian-consent gate for minors (spec 013 · FR-009):
 * it asks for a date of birth and treats an unknown one AS a minor. Here there
 * is no date of birth to ask about and almost no personal data to protect — a
 * name, an email — so a student invitation is REFUSED rather than half-handled,
 * and the person is sent to `/signup/student`, which collects it and accepts the
 * invitation afterwards while signed in. One implementation of the gate, not two.
 */
class RegisterAccount extends Action
{
    /** The workspace roles an invitation may create an account for here. */
    private const STAFF_ROLES = [
        Roles::TENANT_OWNER,
        Roles::TEACHER,
        Roles::ASSISTANT_TEACHER,
    ];

    public function handle(RegisterAccountData $data): User
    {
        $this->resolveInvitation($data->invitationToken, $data->email);

        $user = new User;

        /*
        | forceFill, not fill: `platform_role` and `status` are guarded precisely
        | so a request payload can never choose them.
        |
        | ⚠️ NULL USED TO MEAN «ACADEMY FOUNDER», AND THERE ARE NO NEW FOUNDERS
        | (spec 025 · FR-026) — which is why this door stopped producing null
        | on 2026-09-24: every account it creates now carries an invitation.
        |
        | An invited staff member does get `Teacher`: an assistant sits on the
        | teacher side of every question that reads this column (`ReadLeaderboard`
        | and `ListLeaderboardScopes` both ask «is this a student», never «is this
        | a teacher»). ⚠️ AND THAT IS WHY SPEC 025'S BACKFILL NEEDED A THIRD
        | CONDITION: «teacher who owns no workspace» matches every invited
        | assistant on the platform, so the migration also requires a row in
        | `teacher_applications`, which only `RegisterTeacher` writes.
        |
        | `status` is written explicitly although the column already defaults to
        | `active` — a default is what the row gets when nobody decided, and this
        | is a decision.
        */
        $user->forceFill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'password' => $data->password,
            'platform_role' => PlatformRole::Teacher,
            'status' => UserStatus::Active->value,
        ])->save();

        event(new Registered($user));

        return $user;
    }

    private function resolveInvitation(string $token, string $email): Invitation
    {
        /*
        | `withoutWorkspaceScope()` deliberately: the caller is a guest, so
        | `WorkspaceContext::id()` is null and the scope adds no condition
        | anyway — but relying on that is relying on a guard being inert.
        */
        $invitation = Invitation::query()
            ->withoutWorkspaceScope()
            ->where('token', $token)
            ->first();

        if ($token === '' || $invitation === null) {
            throw new DomainException('هذه الدعوة غير صالحة.');
        }

        if ($invitation->isAccepted()) {
            throw new DomainException('هذه الدعوة استُخدمت من قبل.');
        }

        if ($invitation->isExpired()) {
            throw new DomainException('انتهت صلاحية هذه الدعوة. اطلب دعوة جديدة.');
        }

        /*
        | The invitation names its invitee. Same rule `AcceptInvitation` applies
        | one step later, and it has to be applied here too: without it a
        | forwarded token creates an account for a stranger, who then cannot
        | accept the invitation — leaving a live account nobody asked for.
        */
        if (! hash_equals(mb_strtolower($invitation->email), mb_strtolower($email))) {
            throw new DomainException("أُرسلت هذه الدعوة إلى {$invitation->email}. سجّل بذلك البريد.");
        }

        if (! in_array($invitation->role, self::STAFF_ROLES, true)) {
            throw new DomainException('هذه الدعوة لحساب طالب. أنشئ حسابك من صفحة تسجيل الطلاب ثم اقبل الدعوة.');
        }

        return $invitation;
    }
}
