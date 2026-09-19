<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٦ · T117 — قالبُ «فُعِّلت باقةُ الحصص» يصلُ قاعدةً قائمة.
 *
 * ⛔ **وبلا هذا الترحيلِ يسقطُ الإشعارُ في صمتٍ والاختباراتُ كلُّها خضر.**
 * `TemplateRenderer` يرفضُ قالباً غائباً و`DispatchNotification` **يسجّلُ ولا
 * يفشل** — فالطالبُ الذي دفعَ لا يعلمُ أنّ باقتَه فُعِّلَت ورصيدَه زاد. ولا
 * يراهُ اختبارٌ واحد، لأنّ `tests/Pest.php` يزرعُ الفهرسَ قبلَ كلِّ حالة:
 * البذرةُ مرجعُ بياناتٍ لا تجهيزَ حالة، والفرقُ بينَهما هو هذا الملفُّ
 * بالضبط. خامسُ صفٍّ يُشحَنُ بهذه الآليّةِ في هذه الشجرة.
 *
 * و`seedMissing()` لا `run()`: كلُّ صفٍّ قابلٌ للتحريرِ من `/admin`،
 * و`updateOrCreate` في مسارِ النشرِ يمسحُ كلَّ نصٍّ ضبطَه المشغِّلُ في كلِّ
 * إصدار.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /** فارغةٌ عمداً — انظر سابقاتِها. */
    public function down(): void {}
};
