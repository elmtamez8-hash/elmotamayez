<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٤ — الصفوفُ الثلاثةُ التي تضيفُها هذه المرحلة، تصلُ قاعدةً قائمة.
 *
 * ⚠️ **السابعةُ في هذه الشجرة، وطريقةُ عُطلِها هي الصامتة**: `TemplateRenderer`
 * يرفضُ قالباً غائباً، و`DispatchNotification` **يسجّلُ ولا يفشل** — فالطالبُ
 * لا يُبلَّغُ أنّ الإدارةَ أسنَدَته، ولا أنّ طلبَ انتقالِه سقط، والمدرّسُ لا
 * يعلمُ أنّ باقةً أُنشِئَت باسمِه: بلا خطأٍ في أيِّ موضع، ومع بناءٍ أخضرَ
 * بالكامل، لأنّ `tests/Pest.php` يزرعُ الفهرسَ قبلَ كلِّ اختبارِ Feature.
 *
 * ⚠️ **وواحدةٌ للثلاثةِ لا ثلاث**: `seedMissing()` تمرُّ على الفهرسِ كلِّه، فثلاثُ
 * هجراتٍ ثلاثُ مرّاتٍ للعملِ نفسِه.
 *
 * ⚠️ **و`seedMissing()` لا `run()`**: كلُّ صفٍّ قابلٌ للتحريرِ من `/admin`،
 * و`updateOrCreate` في مسارِ النشرِ يمسحُ كلَّ نصٍّ عربيٍّ ضبطَه المشغِّلُ — في
 * كلِّ إصدار.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /**
     * فارغةٌ عمداً. حذفُ الصفوفِ يُعيدُ الأنواعَ الثلاثةَ إلى حالةِ السقوطِ
     * الصامتِ التي كُتِبَت هذه الهجرةُ لإخراجِها منها،
     * و`NotificationTemplateCoverageTest` يرفضُ نوعاً بلا قالب — فتراجُعٌ يحذفُها
     * يُحمِّرُ البناءَ الذي يتراجعُ إليه.
     */
    public function down(): void {}
};
