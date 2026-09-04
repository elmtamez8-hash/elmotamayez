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
 * The plain account door: an academy founder, or somebody a workspace invited.
 *
 * ⚠️ IT CARRIED NO MIDDLEWARE AT ALL UNTIL 2026-08-29. `POST /auth/register` had
 * no rate limit and no idempotency key — the one unthrottled account-minting
 * endpoint on the platform — and spec 011 is the phase that would have hung
 * money on account creation (a referral reward), which is what turns an
 * unthrottled mint into a free one.
 *
 * ⚠️ AND IT SERVES TWO PEOPLE, WHICH IS WHY THE INVITATION IS OPTIONAL. An
 * academy founder registers with nothing in hand and creates a workspace
 * afterwards — `AcademySignupUnchangedTest` calls that «the only way an academy
 * gets onto the platform», and an earlier version of this fix required a token
 * and closed it. An invitee arrives from `/invitations/{token}` with one. The
 * token is not the guard on this door; it is a claim that has to be checked when
 * it is made.
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
        $invitation = $data->invitationToken === null
            ? null
            : $this->resolveInvitation($data->invitationToken, $data->email);

        $user = new User;

        /*
        | forceFill, not fill: `platform_role` and `status` are guarded precisely
        | so a request payload can never choose them.
        |
        | ⚠️ NULL USED TO MEAN «ACADEMY FOUNDER», AND THERE ARE NO NEW FOUNDERS.
        | The platform enum is three-valued — student · teacher · parent — and
        | somebody who founded a workspace was none of them; owning one was a
        | WORKSPACE role granted by `CreateWorkspace` a request later. Spec 025 ·
        | FR-007 closes that later request (`POST /workspaces` now needs a platform
        | permission), and FR-026 records the closure as a decision: the platform
        | has teachers under it and no academy layer above them. So this branch
        | still produces null, correctly — the value is «not one of the three» —
        | but the account it produces has no path to a workspace except a platform
        | administrator creating one from `/admin` (FR-009).
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
            'platform_role' => $invitation === null ? null : PlatformRole::Teacher,
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

        if ($invitation === null) {
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
