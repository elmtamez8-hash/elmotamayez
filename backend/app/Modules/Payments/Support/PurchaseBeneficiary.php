<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use DomainException;

/**
 * لمن يُشترى هذا الطلب — ومن أنشأَه نيابةً عنه.
 *
 * ⚠️ **وليُّ الأمرِ كانَ يشتري لنفسِه، وهو ما بلَّغَ عنه المستخدِمُ في ٢٠٢٦-٠٩-٠٨.**
 * `PurchaseSubscription` كانت تكتبُ `user_id = المشتري` بلا سؤال، فوليُّ أمرٍ ضغطَ
 * «اشترك» صارَ هو **الطالبَ**: اشتراكٌ باسمِه، وتسجيلٌ في الكورسِ باسمِه، وعضويّةٌ في
 * المجموعةِ باسمِه — بينما ابنُه الذي دُفِعَ من أجلِه لا يملكُ شيئاً. لا شيءَ يفشلُ،
 * والطلبُ يظهرُ في `‎/orders` صحيحاً تماماً.
 *
 * ⚠️ **والدَّورانِ على الصفِّ موجودانِ سلفاً منذُ ٠٢٤، ولا عمودَ ثالثَ يُخترَعُ لهما.**
 * ترويسةُ هجرةِ `granted_by` تقولُها بنصِّها: `user_id` هو **الطالبُ — صاحبُ الطلبِ
 * والرصيد، حتّى حينَ لم يلمسْ لوحةَ مفاتيح**، و`granted_by` هو من أنشأَ الطلبَ
 * نيابةً عنه. فوليُّ الأمرِ هو نفسُ شكلِ الموظّفِ الماليِّ خطوةً أبعد — ومعنى ذلك
 * أنّ **كلَّ مستمعٍ أسفلَ السلسلةِ يعملُ صحيحاً بلا تغييرٍ حرف**: `ActivateSubscription`
 * و`CreateEnrollmentFromOrder` و`ApproveOrder` تقرأُ `$order->user` طالباً، وهو
 * الطالبُ فعلاً.
 *
 * ⚠️ **والصلاحيّةُ تُسألُ من `GuardianDirectory`، لا من شرطٍ يُكتَبُ هنا.**
 * `RecordTermsConsent` يسألُ العقدَ نفسَه بالقيمةِ نفسِها؛ وتهجئةٌ ثانيةٌ لسؤالٍ
 * واحدٍ هي العطبُ الذي دفعَ ثمنَه `BookingEligibility` و`ListLeaderboardScopes`.
 * و`Constitution III` تمنعُ `Payments` من الاستعلامِ عن `parent_student_relations`
 * بنفسِها أصلاً — الجدولُ مملوكٌ للمنصّةِ ولا نطاقَ مساحةٍ يحرسُه.
 *
 * ⚠️ **وجملةُ الرفضِ واحدةٌ لثلاثِ حالات**: لا طالبَ بهذا المعرِّف، وطالبٌ لستَ
 * وصيَّه، ووصايةٌ بلا صلاحيّةِ «الدفع». تمييزُها يجعلُ من الحقلِ مِسبارَ هويّةٍ —
 * يُمرِّرُ الفضوليُّ معرِّفاً فيعرفُ من الردِّ أنّه لطالبٍ حقيقيّ. القاعدةُ نفسُها
 * التي جعلت `CreateFreezePeriod` يسألُ `EnrollmentDirectory` قبلَ أن يكتب.
 */
final class PurchaseBeneficiary
{
    public function __construct(
        private readonly GuardianDirectory $guardians,
    ) {}

    /**
     * @param  string|null  $studentUuid  الطالبُ الذي يُشترى له، أو `null` لمن يشتري لنفسِه
     * @return array{student: User, grantedBy: ?User}
     */
    public function resolve(User $caller, ?string $studentUuid): array
    {
        if ($studentUuid === null) {
            /*
            | ⚠️ **ولا افتراضَ «لنفسِه» لوليِّ الأمر — الافتراضُ هو العطبُ عينُه.**
            | حسابُ وليِّ الأمرِ ليسَ حسابَ متعلِّم: `dashboardAudience` على الواجهةِ
            | يقرأُ `parent` وصيّاً ويحجبُ عنه شاشاتِ الطالب، فطلبُ اشتراكٍ باسمِه
            | اشتراكٌ لا يستطيعُ هو نفسُه استعمالَه.
            */
            if ($caller->platform_role === PlatformRole::Parent) {
                throw new DomainException('اختر الطالب الذي تشترك له.');
            }

            return ['student' => $caller, 'grantedBy' => null];
        }

        $student = User::query()->where('uuid', $studentUuid)->first();

        if ($student === null || ! $this->guardians->isAuthorised($caller, $student, GuardianPermission::Payments)) {
            throw new DomainException('لا يمكنك الاشتراك لهذا الطالب.');
        }

        /*
        | وصيٌّ يسمّي نفسَه ليسَ وصيّاً على نفسِه — العلاقةُ لا تُكتَبُ إلى الذات،
        | فالسطرُ فوقَ هذا يرفضُها. وهذا السطرُ لمن ليسَ وليَّ أمرٍ أصلاً: طالبٌ
        | يسمّي نفسَه صراحةً هو نفسُه المشتري، بلا `granted_by` يزعمُ وساطةً.
        */
        if ($student->getKey() === $caller->getKey()) {
            return ['student' => $caller, 'grantedBy' => null];
        }

        return ['student' => $student, 'grantedBy' => $caller];
    }
}
