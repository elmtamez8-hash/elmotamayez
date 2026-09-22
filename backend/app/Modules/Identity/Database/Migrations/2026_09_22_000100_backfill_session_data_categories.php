<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * سجلُّ الجلساتِ والأجهزةِ يدخلانِ الكتالوجَ (`auth_session` · `device`).
 *
 * ⛔ الجدولانِ قائمانِ منذُ ٢٠٢٦-٠٨-٠٦ ولا صفَّ لأيٍّ منهما: لا مدّةَ احتفاظٍ،
 * ولا مسارَ تصدير، ولا مسارَ محو. وصفٌّ يُكتَبُ لكلِّ تسجيلِ دخولٍ ويبقى إلى
 * الأبد، يحملُ `ip_hash` و`device_id` و`devices.fingerprint_hash`.
 *
 * ⚠️ **والثقبُ الذي تسرّبا منه يُسمّى**: `PersonalDataContractCoverageTest`
 * حارسٌ **لكلِّ وحدة**، فجدولٌ جديدٌ داخلَ وحدةٍ مسجَّلةٍ سلفاً غيرُ مرئيٍّ له.
 * وحدةُ `identity` كانت مسجَّلةً بخمسِ فئاتٍ كلُّها على `users` و
 * `student_profiles` و`parent_student_relations` و`referrals`. وهذه **ثانيةُ**
 * مرّةٍ يُدفَعُ فيها ثمنُ هذا الحدِّ هنا (الأولى `announcements.author_user_id`).
 *
 * ⚠️ و`run()` هنا لا `seedMissing()` — **وتلك غيرُ موجودةٍ على هذا الباذرِ
 * أصلاً**: `run()` هو `firstOrCreate` على المفتاحِ وحدَه، فلا يدهسُ مدّةً
 * عدَّلَها مشغِّلٌ من الشاشة. السابقةُ بالصيغةِ عينِها:
 * `2026_09_16_000200_backfill_guardian_link_data_category`.
 *
 * ⚠️ **ولا نموذجَ يُكتَبُ به هنا**: الباذرُ نفسُه هو الكاتب، وهو يُجرِّبُ
 * الأعمدةَ قبلَ أن يكتبَ (`TranslatableColumns::converted` و`Schema::hasColumn`)
 * — وهجرةٌ تُخفِقُ تأخذُ معها كلَّ ما خلفَها في الطابور، كما حدثَ لصفِّ
 * الكتالوجِ وقالبِ التنبيهِ في نشرِ `course_waitlist_entries`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /*
    | ⚠️ لا `down()` يحذفُ الصفَّين. حذفُ فئةٍ من الكتالوجِ يُعيدُ الجدولَ الذي
    | تصفُه غيرَ مُصرَّحٍ به، وهو الحالُ الذي وُجِدَت هذه الهجرةُ لإنهائِه —
    | والتراجعُ عن إعلانٍ ليس تراجعاً عن جمعِ البيانات.
    */
    public function down(): void {}
};
