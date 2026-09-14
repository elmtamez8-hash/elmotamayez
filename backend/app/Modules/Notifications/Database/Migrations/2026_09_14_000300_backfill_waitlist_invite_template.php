<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٤ · US4 — قالبُ «فُتح مكان» يصلُ قاعدةً قائمة.
 *
 * ⚠️ **وطريقةُ عُطلِه هي الصامتة**: `TemplateRenderer` يرفضُ قالباً غائباً،
 * و`DispatchNotification` **يسجّلُ ولا يفشل** — فالمدعوُّ من الدَّورِ لا يعلمُ
 * أنّه دُعي، ومقعدُه يذهبُ إلى غيرِه وهو ينتظر. بلا خطأٍ في أيِّ موضع، ومع
 * بناءٍ أخضرَ بالكامل، لأنّ `tests/Pest.php` يزرعُ الفهرسَ قبلَ كلِّ اختبار.
 *
 * و`seedMissing()` لا `run()`: كلُّ صفٍّ قابلٌ للتحريرِ من `/admin`،
 * و`updateOrCreate` في مسارِ النشرِ يمسحُ كلَّ نصٍّ ضبطَه المشغِّلُ في كلِّ إصدار.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /** فارغةٌ عمداً — انظر سابقتَها في هذه المرحلة. */
    public function down(): void {}
};
