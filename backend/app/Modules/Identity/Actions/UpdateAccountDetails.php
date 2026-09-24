<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;

/**
 * تصحيحُ بياناتِ حسابٍ من لوحةِ المنصّة — بياناتِ الإنسان، لا قراراتِ المنصّةِ عنه.
 *
 * ⚠️ القائمةُ بيضاءُ صريحةٌ لأنّ الكتابةَ بـ`forceFill`، وهي تتخطّى `$guarded`.
 * فـ`is_super_admin` و`platform_role` محروسانِ هناك بالضبطِ لأنّ حمولةً عامّةً
 * لا يجوزُ أن تصلَهما — ومصفوفةٌ تمرُّ خاماً تكتبُهما بلا صوت.
 *
 * والثلاثةُ المستثناةُ ليست سهواً، وكلُّ واحدةٍ قِيسَت:
 *
 * • `status` عمودٌ بقيمتَين اثنتَين لا غير — `active` و`pending_guardian_consent`.
 *   فـ«تعديلُ الحالة» ليس إلّا انتقالاً واحداً، وهو موافقةُ وليِّ الأمر:
 *   {@see ActivateStudentAccount} تملكُه، و`StartAuthSession` يرفضُ الدخولَ قبلَه.
 *   مشرِفٌ يقلبُه من نموذجٍ **يختلقُ موافقةً قانونيّةً لم تحدث**.
 *
 * • `platform_role` يقرّرُ أيَّ منتَجٍ يرى صاحبُ الحساب، وتغييرُه بعدَ التسجيلِ
 *   يتركُ صفوفَ الملفِّ التي كتبَها إجراءُ التسجيلِ تشيرُ إلى صفةٍ لم تعدْ صفتَه.
 *
 * • `is_super_admin` صلاحيّةُ المنصّةِ كلِّها، وليست حقلاً في دفترِ حسابات.
 *
 * ⚠️ وتغييرُ البريدِ يُسقِطُ توثيقَه، وهذا هو القرارُ الذي يملكُه هذا الإجراء:
 * `email_verified_at` جوابٌ عن **العنوانِ الذي وُثِّق**، فنقلُه إلى عنوانٍ جديدٍ
 * يقولُ إنّ إنساناً أثبتَ ملكيّةَ بريدٍ لم يُرسَلْ إليه شيءٌ قطّ.
 *
 * ⛔ **ويُخبَرُ صاحبُ الحسابِ بتغييرِ بريدِه، من أيِّ بابٍ جاء.** البريدُ هو حيثُ
 * يذهبُ رابطُ استعادةِ كلمةِ المرور، فتغييرُه نقلٌ لملكيّةِ الحسابِ لا تصحيحُ حقل.
 * والتنبيهُ هنا لا في المُتحكِّم لأنّ هذا الإجراءَ بابانِ: `PATCH /auth/me`
 * (صاحبُ الحسابِ، بكلمةِ مرورِه) ولوحةُ المنصّة (مشرِف) — وتنبيهٌ في أحدِهما
 * وحدَه هو التغييرُ الذي يمرُّ بصمتٍ من الآخَر.
 */
class UpdateAccountDetails extends Action
{
    public function __construct(private readonly DispatchNotification $notify) {}

    /**
     * @param  array{first_name?: string, last_name?: string, email?: string, phone?: string|null, country?: string|null}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        $editable = array_intersect_key($attributes, array_flip([
            'first_name',
            'last_name',
            'email',
            'phone',
            'country',
        ]));

        // مقارنةٌ بلا حساسيّةِ حالةٍ وبلا فراغاتٍ طرفيّة: «Ali@X.com» و«ali@x.com»
        // بريدٌ واحد، وإسقاطُ التوثيقِ عليهما عقوبةٌ على مسافةٍ زائدة.
        $emailChanged = isset($editable['email']) && self::emailDiffers($user, $editable['email']);

        if ($emailChanged) {
            $editable['email_verified_at'] = null;
        }

        $user->forceFill($editable)->save();

        if ($emailChanged) {
            $this->notify->handle(new NotificationRequest(
                recipient: $user,
                type: NotificationType::SecurityAlert,
                variables: [
                    'name' => $user->name,
                    'event' => 'تغيّر البريد الإلكتروني المسجَّل لحسابك. إن لم تكن أنت من غيّره، غيّر كلمة المرور فوراً وأنهِ الجلسات التي لا تعرفها.',
                ],
                // Where the reader can see every signed-in device and end one.
                actionUrl: '/settings/security',
            ));
        }

        return $user;
    }

    /**
     * «Is this a different inbox?» — the one spelling, read by this Action AND by
     * the request that decides whether a password is required for the change.
     * Two spellings would let one ask for a password over a capital letter while
     * the other waves a real change through.
     */
    public static function emailDiffers(User $user, string $email): bool
    {
        return mb_strtolower(trim($email)) !== mb_strtolower((string) $user->email);
    }
}
