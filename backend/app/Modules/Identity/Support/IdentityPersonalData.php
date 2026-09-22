<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Compliance\Support\Anonymiser;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Identity's half of the data-rights contract (spec 013).
 *
 * ⚠️ THE `users` ROW IS ANONYMISED, NEVER DELETED — and this is the single most
 * consequential line in any implementor of this contract.
 *
 * `workspaces.owner_user_id` is `cascadeOnDelete()`, and every other table carries
 * `workspace_id` with NO foreign key. So deleting a teacher's account destroys
 * their workspace and leaves their courses, sessions and enrolments pointing at an
 * id that no longer exists — unreachable behind `WorkspaceScope` and undeleted, at
 * the same time. And a database cascade INSTANTIATES NO MODEL, so the ledger's
 * own append-only guard never fires: the claim that `SC-007` holds "by
 * construction" would quietly stop being true.
 *
 * @see PersonalDataOwner
 */
class IdentityPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'identity';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return [
            'student_name',
            'contact_phone',
            'date_of_birth',
            'guardian_link',
            'referral_record',
            // Spec 038 — never swept, never exported, never erased until now.
            'auth_session',
            'device',
        ];
    }

    /**
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        $user = $subject->user;

        yield 'student_name' => [[
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'country' => $user->country,
            'created_at' => $user->created_at?->toIso8601String(),
        ]];

        yield 'contact_phone' => [[
            'phone' => $user->phone,
        ]];

        $profile = $user->studentProfile()->first();

        /*
        | ⚠️ THE KEY IS YIELDED EVEN WHEN THERE IS NO PROFILE. A teacher has none, so
        | the `if` alone produced no `date_of_birth.json` at all — and a file that
        | does not exist is silence, where "we hold no date of birth for you" is the
        | answer a person asking is entitled to. `ExportWalk::none()` carries the
        | same reason for the six modules that bail out for their own reasons.
        */
        if ($profile === null) {
            yield 'date_of_birth' => [];
        }

        if ($profile !== null) {
            yield 'date_of_birth' => [[
                'date_of_birth' => $profile->date_of_birth,
                // ⚠️ SHIPPED WITH THE DATE, because a date we GUESSED from a
                // guardian's stated age is a different fact from one the person
                // gave us — and the subject is entitled to know which they are
                // looking at before they correct it.
                'is_estimated' => $profile->dob_is_estimated,
                // The stage stays, DERIVED (spec 022) — an export is read by the
                // subject, and a stage they never stated is a fact about them
                // they cannot check. The year they did state ships beside it.
                'grade_level' => $profile->stageSlug(),
                'school_year' => $profile->school_year_slug,
                // Passed through as stored: the column carries no cast on the model,
                // so treating it as a Carbon here is a runtime error waiting for the
                // first erased account with a transfer date.
                'ownership_transferred_at' => $profile->ownership_transferred_at,
            ]];
        }

        /*
        | ⚠️ GUARDIANS ARE PART OF THE SUBJECT'S OWN RECORD, and they are gated.
        | A guardian who was granted "attendance" alone must not receive the list
        | of the OTHER guardians — that is personal data about third parties, and
        | a custody dispute is exactly the situation a rights request is made in.
        */
        /*
        | ⚠️ **والمفتاحُ يُذكَرُ ولو لم يُؤذَنْ بقراءتِه** — تهجئةُ `date_of_birth`
        | فوقَه وللسببِ عينِه: ملفٌّ غائبٌ صمتٌ، و«لا علاقةَ مسجَّلةً لك» جوابٌ
        | يستحقُّه مَن سأل. وهو أيضاً ما يُبقي `describe()` صادقةً، إذ يُقارِنُها
        | `PersonalDataContractCoverageTest` بما خرجَ فعلاً.
        */
        if (! $subject->mayReceive(GuardianPermission::DataRights)) {
            yield 'guardian_link' => [];
        }

        if ($subject->mayReceive(GuardianPermission::DataRights)) {
            /*
            | ⚠️ **كانَ يُودَعُ تحتَ `student_name`، وقد صارَ له مفتاحُه.** هذا
            | التعليقُ نفسُه كانَ يقولُ السببَ ويصفُه عطباً: «كلُّ مفتاحٍ يُنتِجُ
            | ملفّاً في الأرشيف، ومفتاحٌ لا يُصرِّحُ به صفٌّ في `data_categories`
            | ملفٌّ بلا قاعدةِ حفظٍ وبلا مالك». وصفُّ `guardian_link` أُضيفَ في
            | ٢٠٢٦-٠٩-١٦ فزالَ العذر.
            |
            | ⚠️ **والطرفانِ معاً، لا الطالبُ وحدَه.** الصفُّ يصفُ اثنَين، ووليُّ
            | أمرٍ يطلبُ نسخةَ بياناتِه كانَ يُجابُ بأرشيفٍ لا ذِكرَ فيه لأطفالِه
            | الذين ربطَهم بيدِه — ومنهم مَن لا حسابَ له، فلا نسخةَ لبياناتِه في
            | أيِّ مكانٍ آخر.
            */
            yield from ExportWalk::keyed(
                'guardian_link',
                ParentStudentRelation::query()
                    ->where('student_user_id', $user->getKey())
                    ->orWhere('guardian_user_id', $user->getKey()),
                fn (ParentStudentRelation $relation): array => [
                    /*
                    | جهةُ القارئِ من العلاقة، بلا تسميةِ الطرفِ الآخر — وهي
                    | التهجئةُ نفسُها التي يستعملُها مشيُ الإحالاتِ تحتَه.
                    */
                    'side' => (int) $relation->guardian_user_id === (int) $user->getKey()
                        ? 'guardian'
                        : 'student',
                    /*
                    | ⚠️ ويُذكَرُ الاسمُ لوليِّ الأمرِ وحدَه: هو ما كتبَه هو عن
                    | طفلِه، وطفلٌ بلا حسابٍ لا نسخةَ لاسمِه في مكانٍ آخر. وفي
                    | أرشيفِ الطالبِ يكونُ اسمَ نفسِه ولا يُضيفُ شيئاً.
                    */
                    'child_name' => (int) $relation->guardian_user_id === (int) $user->getKey()
                        ? $relation->student_name
                        : null,
                    'relation_type' => $relation->relation_type,
                    'status' => $relation->status,
                    'permissions' => $relation->permissions,
                    'created_at' => ExportWalk::at($relation->created_at),
                ],
            );
        }

        /*
        | Spec 011 · US3 — invitations, in both directions.
        |
        | ⚠️ THE OTHER PARTY IS NEVER NAMED, and that is the same decision
        | `ReferralResource` makes on the screen: an inviter already knows who
        | they invited, and an archive that hands somebody a list of names and
        | email addresses assembled out of other people's signups is personal
        | data about third parties — the exact reason the guardian list two
        | blocks up is gated. Nothing here identifies anybody but the subject.
        |
        | ⚠️ AND `flagged_reason` IS OMITTED. It is written for a human reviewing
        | abuse; handing the suspected party the rule they tripped is a tuning
        | guide for the next attempt.
        |
        | The code itself is filed under the same category rather than getting
        | one of its own: a key yielded with no `data_categories` row behind it
        | is a file in the archive with no retention rule and no owner.
        */
        $code = ReferralCode::query()->where('user_id', $user->getKey())->first();

        // Yielded even when empty — «we hold no invitation code for you» is an
        // answer, and a missing file is silence.
        yield 'referral_record' => $code === null ? [] : [[
            'code' => $code->code,
            'created_at' => ExportWalk::at($code->created_at),
        ]];

        yield from ExportWalk::keyed(
            'referral_record',
            Referral::query()
                ->where('referrer_user_id', $user->getKey())
                ->orWhere('referred_user_id', $user->getKey()),
            fn (Referral $referral): array => [
                'uuid' => $referral->uuid,
                // Which side they were on, without naming who was on the other.
                'direction' => (int) $referral->referrer_user_id === (int) $user->getKey()
                    ? 'sent'
                    : 'received',
                'status' => $referral->status->value,
                'invited_at' => ExportWalk::at($referral->created_at),
                'completed_at' => ExportWalk::at($referral->completed_at),
                'reversed_at' => ExportWalk::at($referral->reversed_at),
            ],
        );

        /*
        | Spec 038 · FR-012 — the sign-in log, in the subject's own archive.
        |
        | The spec opens on this: «أين دخلَ حسابُك» was the sharpest thing in this
        | table and it never reached the person it is about. Declaring the two
        | categories drags the export and the erasure arms with it; shipping one
        | without the others is what `PersonalDataContractCoverageTest` fails on.
        |
        | ⛔ `ip_hash` IS GATED, AND IT IS NOT A HASH. `StartAuthSession` writes
        | `hash('sha256', $request->ip())` unsalted over a 2³² input space — a full
        | reverse table is minutes of commodity compute, so the column is a
        | READABLE ADDRESS and the file is a rolling location history. Three
        | measured facts decide the gate: the archive is downloaded by the
        | REQUESTER rather than the subject, an export is dispatched with no
        | officer in the loop, and a guardian holding `DataRights` may open one for
        | a child — the custody dispute this file already names two blocks up.
        |
        | ⚠️ AND THIS DOES NOT CONTRADICT `ExportFieldAllowlist`, whose comment
        | says the absence of `ip_address`/`ip_hash` from the ban list is
        | deliberate. That decision was written about `terms_consents.ip_address`
        | — ONE address that is the evidence of ONE consent — not about a log of
        | every place an account has been. So the distinction lives in the arm,
        | not in the ban list, exactly as `guardian_link`'s does.
        |
        | ⚠️ `device_label` AND NOT `device_id`: the id names nothing on its own and
        | the label is the closed 6×6 set the screen already renders. The
        | fingerprint is banned outright (`ExportFieldAllowlist::forbiddenKeys()`).
        |
        | ⚠️ `surface` is derived from `token_id`, the same spelling
        | `AuthSessionResource` uses — never from `session_id`, which FR-015 nulls.
        | Two spellings of one question is how the screen and the archive end up
        | disagreeing about which door somebody came in by.
        */
        $ownRequest = $subject->grantedScope === null;

        yield from ExportWalk::keyed(
            'auth_session',
            AuthSession::query()
                ->with('device:id,label')
                ->where('user_id', $user->getKey()),
            fn (AuthSession $session): array => [
                'surface' => $session->token_id === null ? 'panel' : 'app',
                'status' => $session->status,
                'ended_reason' => $session->ended_reason?->value,
                'device_label' => $session->device->label,
                // `started_at` is `created_at`; there is no column of that name.
                'started_at' => ExportWalk::at($session->created_at),
                'last_active_at' => ExportWalk::at($session->last_active_at),
                'ended_at' => ExportWalk::at($session->ended_at),
            ] + ($ownRequest ? ['ip_hash' => $session->ip_hash] : []),
        );

        yield from ExportWalk::keyed(
            'device',
            Device::query()->where('user_id', $user->getKey()),
            fn (Device $device): array => [
                'label' => $device->label,
                'created_at' => ExportWalk::at($device->created_at),
                'last_seen_at' => ExportWalk::at($device->last_seen_at),
            ],
        );
    }

    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode === ErasureMode::Retain) {
            return 0;
        }

        $user = $subject->user->fresh();

        if ($user === null) {
            return 0;
        }

        /*
        | ⛔ SPEC 038 · FR-014 — ABOVE THE GUARD, NOT BELOW IT.
        |
        | `alreadyAnonymised()` returns before the transaction for any account
        | carrying the marker, which is every account erased before this feature
        | shipped. Written below the guard, these two arms would never reach one of
        | them: no fresh erasure request arrives for an account already erased, and
        | the nightly age arm touches OLD ENDED sessions only — while an erased
        | account may still hold a live one. So every address and every fingerprint
        | belonging to everyone erased to date would survive FOR EVER.
        |
        | ⚠️ AND THE GUARD ITSELF IS NOT WEAKENED. It is what makes
        | `ExecuteDataErasure`'s `$done < $limit` loop terminate — so the arm above
        | it has to CONVERGE on its own: each statement selects only rows that are
        | not yet anonymised, so a second pass over the same account writes nothing
        | and the loop ends on the next batch.
        */
        $swept = app(AuthSessionRetention::class)->eraseFor($user->getKey());

        if ($this->alreadyAnonymised($user)) {
            return $swept;
        }

        /*
        | ⚠️ ONE TRANSACTION FOR THE WHOLE PERSON, not one per table. A half
        | anonymised account — name cleared, phone kept — is a re-identification
        | hole, and erasure does not reverse. The row count is small and bounded,
        | so there is no batching argument against it.
        */
        return DB::transaction(function () use ($user): int {
            $anonymiser = app(Anonymiser::class);

            $user->forceFill([
                'first_name' => $anonymiser->name(),
                'last_name' => '',
                'email' => $anonymiser->email($user->getKey()),
                'phone' => null,
                'country' => null,
            ])->save();

            $user->studentProfile()->update([
                'date_of_birth' => null,
                'dob_is_estimated' => null,
                'guardian_contact' => null,
            ]);

            /*
            | The relations name a child by hand-written string, so they go
            | entirely rather than being blanked.
            |
            | ⚠️ **والطرفانِ معاً، وكانَ الطالبُ وحدَه.** وليُّ أمرٍ يُجهَّلُ
            | حسابُه كانَ يترُكُ خلفَه صفوفاً تحملُ **أسماءَ أطفالِه** مكتوبةً
            | بيدِه — ومنهم مَن لا حسابَ له فلا شيءَ يُجهَّلُ عنه من ناحيةٍ أخرى.
            | وتجهيلُ نصفِ علاقةٍ ثقبُ إعادةِ تعرُّفٍ لا تجهيل.
            */
            ParentStudentRelation::query()
                ->where('student_user_id', $user->getKey())
                ->orWhere('guardian_user_id', $user->getKey())
                ->delete();

            return 1;
        });
    }

    /** @param list<int> $exemptUserIds */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        /*
        | ⚠️ NOTHING IN THIS MODULE EXPIRES ON A CLOCK, and saying so is the answer
        | rather than an omission. A name, a phone number and a date of birth are
        | held for as long as the ACCOUNT is — they have no age of their own, and a
        | sweep that deleted a living user's name after N days would break the
        | product on a schedule. Their catalogue rows carry a null retention, so
        | the sweep never reaches here; this method exists because the contract has
        | five functions and a silent `return 0` with no reason is how the next
        | reader concludes it was forgotten.
        */
        return 0;
    }

    private function alreadyAnonymised(User $user): bool
    {
        return str_starts_with((string) $user->email, 'anonymised+');
    }
}
