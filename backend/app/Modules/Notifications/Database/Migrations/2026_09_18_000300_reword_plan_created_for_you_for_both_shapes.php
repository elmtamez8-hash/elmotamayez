<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٦ · T076 — قالبُ «أُنشئت باقة باسمك» يصفُ الشكلَينِ لا المدّةَ وحدَها.
 *
 * ⛔ **وبدونَه تسقطُ الرسالةُ كلَّها في صمتٍ لكلِّ باقةِ حصص.** النصُّ الذي
 * شحنَه ٠٣٤ يقرأُ `{{ duration_days }}`، وباقةُ الحصصِ لا تحملُ مدّةً إطلاقاً —
 * و`TemplateRenderer` يرفضُ متغيّراً فارغاً بينما `DispatchNotification`
 * **يسجّلُ ولا يفشل**. فالمدرّسُ الذي أُنشئَت باسمِه باقةٌ وسُعِّرَت لا يعلمُ
 * بها أبداً، والصفُّ سليمٌ والاختباراتُ خضر.
 *
 * ⚠️ **وهو تحديثٌ لصفٍّ قائمٍ، وهذا ما لا تفعلُه `seedMissing()` عمداً.** تلك
 * تكتبُ الغائبَ وحدَه لأنّ كلَّ صفٍّ قابلٌ للتحريرِ من `/admin`، و`run()` في
 * مسارِ النشرِ يمسحُ كلَّ نصٍّ ضبطَه المشغِّل. فالكتابةُ هنا **مشروطةٌ بأنّ
 * النصَّ ما زالَ نصَّ ٠٣٤ حرفاً بحرف**: مَن حرَّرَه يبقى تحريرُه، ويبقى معه
 * العطبُ — وهو ثمنٌ مقصودٌ، لأنّ استبدالَ كلماتِ مشغِّلٍ بلا إذنِه أسوأُ من
 * رسالةٍ ناقصةٍ يراها ويُصلِحُها.
 *
 * ⚠️ **وبالنموذجِ لا بـ`DB::table()`**: `message_templates.body` عمودٌ مترجَمٌ
 * منذُ ٠٥٥ — أي مستندُ JSON — فكتابةٌ خامٌّ فيه تضعُ سلسلةً عاريةً تُقرَأُ
 * فراغاً، وهي عينُ الزلّةِ التي دفعَ ثمنَها `UpdateAnnouncement`.
 */
return new class extends Migration
{
    /** نصُّ ٠٣٤ حرفاً بحرف — الشرطُ الذي تُكتَبُ عندَه وحدَه. */
    private const SHIPPED_BODY = 'أنشأت إدارة المنصّة باقة «{{ plan_title }}» باسمك بسعر {{ price }} لمدّة {{ duration_days }} يوماً. راجعها في باقاتك، وتواصل مع الإدارة إن كان فيها ما يحتاج تعديلاً.';

    private const REWORDED_BODY = 'أنشأت إدارة المنصّة باقة «{{ plan_title }}» باسمك بسعر {{ price }}، وتبيع {{ shape }}. راجعها في باقاتك، وتواصل مع الإدارة إن كان فيها ما يحتاج تعديلاً.';

    public function up(): void
    {
        $template = MessageTemplate::query()
            ->where('type', NotificationType::PlanCreatedForYou->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->first();

        if (! $template instanceof MessageTemplate || $template->body !== self::SHIPPED_BODY) {
            return;
        }

        $template->forceFill([
            'body' => self::REWORDED_BODY,
            'variables' => ['plan_title', 'price', 'shape'],
        ])->save();
    }

    /** فارغةٌ عمداً — انظر سابقاتِها. */
    public function down(): void {}
};
