<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A human decision by the academic team (FR-016), recorded with who and when.
 */
class ApproveTeacherApplication extends Action
{
    public function handle(TeacherApplication $application, User $reviewer): TeacherProfile
    {
        /*
         | ⚠️ «المعتمَدُ وحدَه» لا `isPending()` — والفرقُ قدرةٌ قائمة. زرُّ الاعتمادِ
         | في {@see TeacherProfileResource} يختارُ أيَّ طلبٍ حالتُه ليستْ معتمَدة
         | **بما فيها المرفوضة**: هو مسارُ «غيّرنا رأيَنا في مدرّسٍ رفضناه». فحارسٌ
         | يشترطُ التعليقَ هنا يقتلُ إعادةَ التفعيلِ بدلَ أن يسدَّ ثغرة.
         |
         | وما يسدُّه هذا: `TeacherApproved` له مستمعُ إشعار، فاعتمادٌ ثانٍ يُرسلُ
         | «تمّ اعتمادُ طلبك» مرّتَين ويختمُ مراجِعاً وتاريخاً فوقَ قرارٍ سابق.
         | واللوحةُ كانتْ تُخفي زرَّها فيبدو الأمرُ محروساً، ومسارُ الـAPI بلا شرطٍ.
         */
        if ($application->status === TeacherApplication::STATUS_APPROVED) {
            throw new DomainException('هذا الطلب معتمَد بالفعل.');
        }

        $profile = $application->teacherProfile;

        /*
         | ⚠️ وهذا وحدَه ما يرفضُ «مسوّدة»: الملفُّ يُولَدُ عندَ الإرسال، فطلبٌ لم
         | يُرسَلْ لا ملفَّ له — ولا حاجةَ لحارسِ حالةٍ ثانٍ يقولُ الشيءَ نفسَه.
         */
        if ($profile === null) {
            throw new DomainException('لا يوجد ملف مدرّس مرتبط بهذا الطلب.');
        }

        return DB::transaction(function () use ($application, $profile, $reviewer): TeacherProfile {
            $this->stampParticipation($application, $profile);

            $profile->forceFill([
                'approval_status' => TeacherProfile::STATUS_APPROVED,
                'is_publicly_listed' => self::derivePublicListing($profile),
            ])->save();

            $application->forceFill([
                'status' => TeacherApplication::STATUS_APPROVED,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            // Within a minute of the decision the teacher is on the marketplace
            // (SC-010) — the listing cache is the only thing standing in the way.
            MarketplaceCache::flush();

            event(new TeacherApproved($profile));

            return $profile;
        });
    }

    /**
     * Spec 025 · FR-026 — participation is stamped HERE, at approval, never at birth.
     *
     * ⚠️ The reason is not tidiness. Two of the three readers of
     * `participates_in_marketplace` additionally require `approval_status =
     * approved`; the third —
     * CMS's `Article::publicListingConstraints()` — requires only `status = published` and `published_at <= now()` — no approval predicate at
     * all. So a workspace born participating opens the platform's own blog, under
     * the platform's own domain and pushed to search engines, to any email address
     * that finishes step one of the wizard. Participation alone IS the whole
     * cross-tenant gate there.
     *
     * ⚠️ AND ONLY ON THE TEACHER'S OWN WORKSPACE. A profile that lives in somebody
     * else's academy would otherwise opt that academy in on one member's approval,
     * publicly listing every other teacher in it. 001 · FR-001 (participation is
     * opt-in, default not participating) is preserved by this spec, not repealed —
     * the owner's explicit choice stays the owner's.
     *
     * ⚠️ `forceFill`: the column is NOT in `$fillable` (five columns, none of them
     * this one) and mass assignment discards a non-fillable key in silence. Every
     * existing writer of it uses `forceFill` for exactly that reason.
     */
    private function stampParticipation(TeacherApplication $application, TeacherProfile $profile): void
    {
        $workspace = $profile->workspace;

        if ($workspace === null || $workspace->owner_user_id !== $application->user_id) {
            return;
        }

        $workspace->forceFill(['participates_in_marketplace' => true])->save();

        // derivePublicListing() below reads this relation; without re-seating it
        // the freshly stamped value is invisible and the teacher is approved but
        // unlisted — the marketplace edge that fails with no error anywhere.
        $profile->setRelation('workspace', $workspace);
    }

    /**
     * Derived from (approved × workspace participates), never assigned by hand.
     *
     * Approving a teacher in a workspace that has not opted into the marketplace
     * succeeds and leaves them unlisted. That is correct: the academy decides
     * whether its teachers are offered publicly, the reviewer decides whether this
     * person may teach.
     */
    private static function derivePublicListing(TeacherProfile $profile): bool
    {
        return (bool) $profile->workspace?->participates_in_marketplace;
    }
}
