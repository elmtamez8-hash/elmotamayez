<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عن مَن كلُّ فئةٍ — لا مَن يراها.
 *
 * ⛔ الجدولُ يحملُ `audience` منذُ ٠١٣، وهو جوابٌ عن السؤالِ الآخر: نصٌّ حرٌّ
 * بالعربيّةِ يُسمّي مَن يطّلعُ («المدرّس المسجَّل عنده · ولي الأمر»). فلا شيءَ في
 * الجدولِ كانَ يقولُ لمن الفئةُ نفسُها، وشاشةُ «خصوصيّتي» تعرضُ الثلاثةَ
 * والثلاثينَ صفّاً لكلِّ حساب — فقرأَ مدرّسٌ عن «تقدّمك في الدروس» و«محاولاتك في
 * الاختبارات». بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦.
 *
 * ⚠️ **والقيمُ تُكتَبُ هنا لأنّ `seedMissing()` لا يكتبُها.** الباذرُ يستعملُ
 * `firstOrCreate` على المفتاحِ عمداً — صفٌّ عدَّلَه مشغِّلٌ لا يُدهَسُ في كلِّ
 * نشرة — فقاعدةٌ فيها الصفوفُ سلفاً كانت ستبقى بعمودٍ فارغٍ لا يُرشِّحُ شيئاً أو
 * يُخفي كلَّ شيء، حسبَ كيفَ كُتِبَ الشرط.
 *
 * ⚠️ **وقائمةٌ واحدةٌ لا قائمتان**: القيمُ تُقرَأُ من `DataCategorySeeder::categories()`
 * — `public static` منذُ أن قرأَتْها هجرةُ `erasure_mode` للسببِ عينِه — لا
 * تُنسَخُ هنا. ونسخةٌ ثانيةٌ من ثلاثةٍ وثلاثينَ صفّاً تفترقُ عندَ أوّلِ فئةٍ
 * تُضاف.
 *
 * ⚠️ و`DB::table()` لا النموذج: الهجرةُ تتكلّمُ مخطَّطَ تاريخِها، والنموذجُ
 * يتكلّمُ اليوم — وهي القاعدةُ التي دفعَ ثمنَها تحويلُ ٠٥٥ حينَ سقطَت الحزمةُ
 * كلُّها على `firstOrCreate` في هجرةٍ قديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_categories', function (Blueprint $table): void {
            /*
            | ⚠️ **قابلٌ للفراغِ عن ضرورة، والفراغُ معناه مكتوبٌ على النموذج.**
            | SQLite ترفضُ `ADD COLUMN NOT NULL` بلا قيمةٍ افتراضيّةٍ على جدولٍ
            | فيه صفوف، وMySQL ترفضُ قيمةً افتراضيّةً على عمودِ JSON أصلاً — فلا
            | صيغةَ تمرُّ على المحرّكَينِ معاً. و`->change()` بعدَ الإملاءِ يُعيدُ
            | بناءَ الجدولِ على SQLite، وهي مقايضةٌ رفضَها هذا المستودعُ مرّتَين.
            |
            | و`null` تقرؤُها {@see DataCategory::subjectRoles()} «لا نعرف ⇒
            | اعرضْ للجميع» — الاتّجاهُ الآمنُ نفسُه الذي يحكمُ كلَّ صفٍّ مشكوكٍ
            | فيه هنا.
            */
            $table->json('subject_roles')->nullable()->after('audience');
        });

        foreach (DataCategorySeeder::categories() as $category) {
            DB::table('data_categories')
                ->where('key', $category['key'])
                ->update(['subject_roles' => json_encode($category['subject_roles'])]);
        }

        /*
        | وما لم يُسمِّه الباذرُ — صفٌّ كتبَه مشغِّلٌ بيدِه من `/admin` — يبقى
        | فارغاً عن قصد، ويقرؤُه النموذجُ «للجميع».
        */
    }

    public function down(): void
    {
        Schema::table('data_categories', function (Blueprint $table): void {
            $table->dropColumn('subject_roles');
        });
    }
};
