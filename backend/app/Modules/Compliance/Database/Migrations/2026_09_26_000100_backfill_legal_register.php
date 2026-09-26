<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\PlatformSetting;
use App\Modules\Tenancy\Support\PlatformSettings;
use Database\Seeders\DataCategorySeeder;
use Database\Seeders\DataProcessorSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ٢٠٢٦-٠٩-٢٦ — مراجعةُ الصفحاتِ القانونيّةِ تصلُ قاعدةً قائمة.
 *
 * ثلاثةُ أعمالٍ لا عمل، ولكلٍّ منها سببُه:
 *
 * ١. **صفوفٌ جديدة** (`account_email` في الكتالوج · `brevo` و`hostinger` في سجلِّ
 *    المعالِجين). الباذرانِ `firstOrCreate` على المفتاحِ وحدَه، فتشغيلُهما هنا آمنٌ
 *    ولا يمسُّ صفّاً عدّلَه مشغّل — الشكلُ نفسُه في `_000200_backfill_data_categories`.
 *    والكتالوجُ أوّلاً: صفُّ `brevo` يُسمّي `account_email`، و`ProcessorAllowlistTest`
 *    يرفضُ معالِجاً يُسمّي فئةً لا صفَّ لها.
 *
 * ٢. **صياغةٌ صُحِّحَت في صفوفٍ موجودة** («خارج قطر» · اسمُ وسيطِ واتساب).
 *    ⚠️ `firstOrCreate` لا يُعيدُ صياغةَ صفٍّ موجود، فتصحيحُ الباذرِ وحدَه يبقى في
 *    الشجرةِ ولا يصلُ الإنتاجَ أبداً. والتحديثُ **مشروطٌ بالقيمةِ القديمةِ حرفيّاً**:
 *    صفٌّ غيّرَه مشغّلٌ من اللوحةِ لا يطابقُ فلا يُمَسّ.
 *
 * ٣. **نسخةُ سياسةِ الخصوصيّة** ١.٠ ← ١.١، لأنّ النصَّ تغيّرَ في جوهرِه (معالِجانِ
 *    جديدان، وأساسٌ قانونيّ، ونقلٌ عبرَ الحدود). ⚠️ `config/consents.php` وحدَه لا
 *    يكفي: `PlatformSettingsSeeder` ينسخُ القيمةَ إلى `platform_settings`، والصفُّ
 *    المخزَّنُ يغلبُ الملفّ — فرفعُ الرقمِ في الملفِّ على قاعدةٍ بُذِرَت لا يغيّرُ
 *    شيئاً. والشرطُ `=== '1.0'` لأنّ مشغّلاً نشرَ نسخةً بنفسِه قرارُه لا قرارُنا.
 *
 *    والأثرُ مقيس: بطاقةُ «موافقات مطلوبة» تظهرُ مرّةً على `/billing` لكلِّ حساب،
 *    ولا يُمنَعُ أحدٌ من الدخول — `StartAuthSession` يرفضُ حالةَ `pending` وحدَها،
 *    و`ActivateStudentAccount` لا يُعيدُ فتحَ حسابٍ نشِط.
 *
 * `down()` فارغةٌ عمداً: حذفُ صفِّ كتالوجٍ عندَ التراجعِ يوقفُ مسحَ ما كانَ يُمسَح،
 * وإنزالُ النسخةِ يُبطلُ توقيعاتٍ جُمِعَت على النصِّ الجديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
        (new DataProcessorSeeder)->run();

        $rewordings = [
            ['processing_location', 'خوادم المزوّد خارج قطر', DataProcessorSeeder::VENDOR_ABROAD],
            ['processing_location', 'خوادم مزوّد المتصفّح خارج قطر', DataProcessorSeeder::BROWSER_VENDOR_ABROAD],
            ['name', 'WhatsApp Business', DataProcessorSeeder::WHATSAPP_NAME],
        ];

        foreach ($rewordings as [$column, $old, $new]) {
            DB::table('data_processors')->where($column, $old)->update([$column => $new]);
        }

        $version = PlatformSetting::query()->find('consents.versions.data_processing');

        if ($version !== null && $version->value === '1.0') {
            PlatformSettings::set('consents.versions.data_processing', '1.1');
        }
    }

    public function down(): void {}
};
