<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * فتراتُ التجميدِ تدخلُ الكتالوجَ (`freeze_period`).
 *
 * ⛔ `freeze_periods.student_user_id` و`freeze_period_starts.student_user_id`
 * يُسمّيانِ طالباً بعينِه، ولم يكن لأيٍّ منهما صفٌّ: لا مدّةَ احتفاظ، ولا مسارَ
 * تصدير، ولا مسارَ محو. والثقبُ هو نفسُه في كلِّ مرّة: `PersonalDataContractCoverageTest`
 * حارسٌ **لكلِّ وحدة**، و`livesessions` مسجَّلةٌ سلفاً بـ`attendance_record`،
 * فجدولٌ جديدٌ داخلَها غيرُ مرئيٍّ له.
 *
 * ⚠️ و`run()` لأنّها `firstOrCreate` على المفتاحِ وحدَه، فلا تدهسُ مدّةً عدَّلَها
 * مشغِّلٌ من الشاشة — السابقةُ بالصيغةِ عينِها:
 * `2026_09_22_000100_backfill_session_data_categories`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /*
    | ⚠️ لا `down()` يحذفُ الصفّ: حذفُ فئةٍ يُعيدُ الجدولَ غيرَ مُصرَّحٍ به، وهو
    | الحالُ الذي وُجِدَت هذه الهجرةُ لإنهائِه.
    */
    public function down(): void {}
};
