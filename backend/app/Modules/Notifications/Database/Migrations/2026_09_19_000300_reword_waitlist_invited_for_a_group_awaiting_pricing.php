<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٦ — «سجِّل الآن» تُرسِلُ بعضَ المدعوِّينَ إلى بابٍ يردُّهم.
 *
 * ⛔ **والدعوةُ صحيحةٌ، والجملةُ وحدَها هي الخطأ.** الدعوةُ من الدَّورِ **إسنادٌ
 * لا بيع** (`Learning\InviteFromWaitlist` · FR-020)،
 * فالموظَّفُ يدعو إلى مجموعةٍ ولو لم تُسعَّرْ باقتُها بعد — وهذا مقصودٌ، لأنّ
 * المنعَ يتركُ طابوراً لا يخرجُ منه أحدٌ بقرارٍ ليسَ للطالبِ ولا للمدرّسِ فيه
 * يد. لكنّ النصَّ كانَ يقولُ «**سجِّل الآن**»، و`Learning\JoinCohort`
 * يردُّ كلَّ مجموعةٍ لا يصلُها سعرٌ حيٌّ بـ`cohort_not_listed` — فالمدعوُّ يقرأُ
 * أمراً ويُصطدَمُ برفضٍ، **وصفُّه في الدَّورِ مختومٌ بـ`invited_at` ولا يعودُ
 * مرشَّحاً في أيِّ جولةٍ بعدَها أبداً**. فالخسارةُ ليست محاولةً، هي الدَّورُ كلُّه.
 *
 * ⚠️ **والجملةُ الجديدةُ صادقةٌ في الحالتَين**: مَن يصلُه السعرُ يجدُ زرَّ
 * الانضمامِ في الكورس، ومَن لا يصلُه يقرأُ على الشاشةِ نفسِها أنّ الإدارةَ
 * تُسنِدُه (`Learning\CohortGate`). ولا نوعَ إشعارٍ
 * ثانياً لهذه الحالة: نوعانِ لحدثٍ واحدٍ إملاءانِ يفترقانِ عندَ أوّلِ تعديل.
 *
 * ⚠️ **و«المقعد ليس محجوزاً لك» تبقى حرفاً بحرف** (FR-027): الدَّورُ لا يحجزُ
 * مقعداً ولا يَعِدُ به، وحذفُها مع الأمرِ يجعلُ الرسالةَ وعداً.
 *
 * ⚠️ **ومشروطةٌ بأنّ النصَّ ما زالَ نصَّ ٠٣٤ حرفاً بحرف**، ومكتوبةٌ بالنموذجِ لا
 * بـ`DB::table()` — العمودُ مترجَمٌ منذُ ٠٥٥ فكتابةٌ خامٌّ فيه تُقرَأُ فراغاً.
 * انظر سابقتَها `_000300_reword_plan_created_for_you_for_both_shapes`.
 */
return new class extends Migration
{
    /** نصُّ ٠٣٤ حرفاً بحرف — الشرطُ الذي تُكتَبُ عندَه وحدَه. */
    private const SHIPPED_BODY = 'جاء دورك في «{{ course_title }}»: فُتح مكان في مجموعة «{{ cohort_name }}». سجِّل الآن — المقعد ليس محجوزاً لك، وهو لمن يسبق.';

    private const REWORDED_BODY = 'جاء دورك في «{{ course_title }}»: فُتح مكان في مجموعة «{{ cohort_name }}». افتحِ الكورس لتكمل — المقعد ليس محجوزاً لك، وهو لمن يسبق.';

    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('type', NotificationType::WaitlistInvited->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->first();

        if (! $template instanceof MessageTemplate || $template->body !== self::SHIPPED_BODY) {
            return;
        }

        $template->forceFill(['body' => self::REWORDED_BODY])->save();
    }

    /** فارغةٌ عمداً — انظر سابقاتِها. */
    public function down(): void {}
};
