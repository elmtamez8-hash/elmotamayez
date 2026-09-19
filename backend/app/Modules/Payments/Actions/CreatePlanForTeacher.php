<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Enums\Currency;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Support\PlanShape;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use App\Shared\Contracts\TeacherOffboardingDirectory;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * بابٌ ثانٍ لإنشاءِ باقةِ اشتراك: الإدارةُ تُنشئُها **باسمِ مدرّسٍ منصوصٍ عليه**
 * (٠٣٤ · FR-017 … FR-022).
 *
 * ⚠️ **والمدرّسُ مُعامِلٌ صريح، لا سياق.** FR-018 تمنعُ استنتاجَه من مساحةِ
 * الموظَّف: سياقُ الموظَّفِ يرجعُ إلى `users.last_workspace_id` كأيِّ مستخدمٍ
 * آخر، فباقةٌ تُبنى منه تُكتَبُ في مساحةِ **الموظَّفِ نفسِه** — بلا خطأ، وتظهرُ
 * في كتالوجِ طلابِ مدرّسٍ لم يسمعْ بها.
 *
 * ⚠️ **والسعرُ مُعامِلٌ إلى جانبِ البيانات، لا داخلَها.** {@see SavePlan} يرفضُ
 * `price_minor` ممّن لا يملكُ صلاحيّةَ التسعير — والموظَّفُ هنا يملكُها، فلا
 * يرفضُه — ثمّ **يُسقِطُه العمودُ غيرُ القابلِ للإسناد صامتاً**: فحصٌ في موضعٍ
 * وإسقاطٌ في آخر، ينتهي بباقةٍ بلا سعرٍ والموظَّفُ يظنُّ أنّه سعَّرَها.
 * {@see SetPlanPrice} هو الكاتبُ الوحيدُ للعمود، هنا وفي شاشةِ التسعيرِ سواء.
 *
 * ⚠️ **والرفضُ كلُّه قبلَ أوّلِ كتابة.** ثلاثةُ أسئلةٍ لا سؤال:
 *  · الصلاحيّةُ — وهذا الفعلُ هو البابُ الوحيدُ إليه، ولا سياسةَ تحرسُه خلفَه؛
 *  · مدرّسٌ **غادرَ المنصّة** ({@see TeacherOffboardingDirectory}) — باقةٌ باسمِ
 *    مَن لم يعُدْ هنا تُباعُ وصاحبُها لا يستطيعُ أن يُدرِّسَها؛
 *  · مساحةٌ **ليسَ فيها عضوٌ بدورٍ تدريسيّ** — والسؤالُ بالنفي
 *    (`role != student`) كما في {@see User::teachesOnPlatform()}: دورٌ مخصَّصٌ لا
 *    نعرفُه يسقطُ نحوَ الرفضِ لا نحوَ فتحِ البابِ صامتاً.
 *
 * ⚠️ **والحفظُ والتسعيرُ في معاملةٍ واحدة، والإشعارُ خارجَها.** لو فشلَ التسعيرُ
 * وحدَه بقيَت باقةٌ بلا سعرٍ في طابورِ التسعير — وهي حالةٌ مشروعةٌ في ذاتِها —
 * لكنّ الموظَّفَ يرى خطأً ويُعيدُ المحاولة، فتصيرُ باقتانِ لمدرّسٍ واحد. والإشعارُ
 * بعدَ الالتزامِ لأنّه يُصَفُّ في طابورٍ: مُرسَلٌ داخلَ المعاملةِ قد يُقرَأُ قبلَ
 * أن تُلزَم، فيصلُ المدرّسَ خبرٌ عن باقةٍ لا وجودَ لها.
 *
 * ⚠️ **ومَن أنشأَها يُكتَبُ في السجلِّ لا في المتن** (FR-019). قالبُ الرسالةِ
 * مشحونٌ ومعتمَدٌ ويقولُ «أنشأَت إدارةُ المنصّة» — وإضافةُ اسمٍ إليه تعني تعديلَ
 * صفٍّ في كتالوجٍ يُقرَأُ وقتَ التشغيلِ ومعه هجرةُ ردمٍ، مقابلَ سطرٍ يُقرَأُ في
 * سجلِّ النشاطِ وفيه الفاعلُ نفسُه.
 */
class CreatePlanForTeacher extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly SavePlan $savePlan,
        private readonly SetPlanPrice $setPlanPrice,
        private readonly DispatchNotification $notifications,
        private readonly TeacherOffboardingDirectory $offboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ما يكتبُه المدرّسُ عادةً: العنوانُ والمدّةُ والنوعُ والتغطية.
     */
    public function handle(User $officer, Workspace $workspace, array $data, ?int $priceMinor): Plan
    {
        if (! $officer->can(Permissions::PLANS_PRICE)) {
            throw new DomainException('إنشاء باقة باسم مدرّس من صلاحيات تسعير المنصّة.');
        }

        $workspaceId = (int) $workspace->getKey();

        if ($this->offboarding->hasDeparted($workspaceId)) {
            throw new DomainException('هذا المدرّس غادر المنصّة، فلا تُنشأ باقة باسمه.');
        }

        if (! $this->hasTeachingMember($workspace)) {
            throw new DomainException('لا يوجد في هذه المساحة عضو بدور تدريسيّ تُنسَب إليه الباقة.');
        }

        $plan = DB::transaction(function () use ($officer, $workspaceId, $data, $priceMinor): Plan {
            // بيانات المدرّس وحدها — والسعر معاملٌ مستقلّ، انظر وصف الصنف.
            $plan = $this->savePlan->handle($officer, $workspaceId, $data);

            return $this->setPlanPrice->handle($plan, $priceMinor);
        });

        $this->logActivity('plan.created_for_teacher', $plan, [
            'workspace_id' => $workspaceId,
            'price_minor' => $priceMinor,
            // من السجلِّ لا من الجلسة: `logActivity()` يقرأُ الفاعلَ من
            // `Auth`، وهذا الفعلُ يُستدعى كذلك من حيثُ لا جلسةَ فيه.
            'officer_id' => $officer->getKey(),
        ]);

        $this->notifyTeacher($workspace, $plan, $priceMinor);

        return $plan;
    }

    /**
     * ⚠️ بالنفي: «عضوٌ دورُه ليسَ الطالب». والمالكُ يحملُ صفَّ `tenant-owner`
     * دائماً، فلا فرعَ خاصّاً له.
     */
    private function hasTeachingMember(Workspace $workspace): bool
    {
        return $workspace->members()
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->exists();
    }

    /**
     * ⚠️ المُبلَّغُ هو **مالكُ المساحة**: هو صاحبُ المنتَجِ الذي سيُباعُ باسمِه
     * ويُحاسَبُ عليه. ومساحةٌ بلا مالكٍ حالةٌ لا يكتبُها شيءٌ في الشجرة، فلا
     * إشعارَ ولا استثناء — الباقةُ قد كُتِبَت، وإسقاطُها لأجلِ رسالةٍ خسارةُ
     * العملِ كلِّه مقابلَ نصفِه.
     */
    private function notifyTeacher(Workspace $workspace, Plan $plan, ?int $priceMinor): void
    {
        $teacher = $workspace->owner;

        if (! $teacher instanceof User) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::PlanCreatedForYou,
            variables: [
                'plan_title' => (string) $plan->title,
                'price' => $this->money($priceMinor, (string) $plan->currency),
                /*
                | ⛔ ٠٣٦ — الشكلُ لا المدّة. `(string) null` سلسلةٌ فارغة،
                | و`TemplateRenderer` يرفضُ الفراغَ و`DispatchNotification`
                | **يسجّلُ ولا يفشل** — فباقةُ الحصصِ تُكتَبُ وتُسعَّرُ ولا يعلمُ
                | بها المدرّسُ الذي تُباعُ باسمِه أبداً.
                */
                'shape' => PlanShape::describe(
                    $plan->duration_days === null ? null : (int) $plan->duration_days,
                    $plan->session_count === null ? null : (int) $plan->session_count,
                ) ?? '—',
            ],
            actionUrl: '/manage/plans',
            workspaceId: (int) $workspace->getKey(),
        ));
    }

    /**
     * ⚠️ «بلا سعر» ليسَ صفراً: باقةٌ بلا رقمٍ لا تُعرَضُ للبيعِ أصلاً، و«٠٫٠٠»
     * في رسالةٍ تقولُ للمدرّسِ إنّها تُباعُ مجّاناً.
     */
    private function money(?int $minor, string $currency): string
    {
        if ($minor === null) {
            return 'لم يُحدَّد بعد';
        }

        return number_format($minor / 100, 2).' '.(Currency::tryFrom($currency)?->short() ?? $currency);
    }
}
