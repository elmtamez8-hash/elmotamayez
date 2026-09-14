<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٤ · T044 — صفُّ صنفِ البياناتِ لـ`course_waitlist_entries` يصلُ قاعدةً قائمة.
 *
 * ⚠️ **وحارسُ التغطيةِ لكلِّ وحدةٍ لا يرى جدولاً جديداً داخلَ وحدةٍ مسجَّلة** —
 * وصفُ `PersonalDataContractCoverageTest` يقولُ ذلك بنفسِه. فـ
 * `announcements.author_user_id` شُحِنَ بلا صنفٍ ولا مَشيات، و١٩١٦ اختباراً بقيَت
 * خضراء. فصنفٌ بلا صفٍّ هو جدولٌ لا تنظرُ إليه المَسحةُ الليليّةُ أبداً — بصمت،
 * وكلُّ اختبارٍ أخضر لأنّ `tests/Pest.php` يزرعُ الجدولَ قبلَ كلِّ حالة.
 *
 * و`run()` آمنةٌ هنا لأنّها `firstOrCreate` على المفتاحِ وحدَه: الصفوفُ بياناتٌ
 * مرجعيّةٌ يعدّلُها المشغّلُ من `/admin`، و`updateOrCreate` كانت ستُعيدُ كلَّ مدّةٍ
 * غيَّرَها إلى ما في الشجرة. و`down()` فارغةٌ عمداً: حذفُ صفٍّ عندَ التراجعِ يوقفُ
 * مَسحَ جدولٍ كانَ يُمسَح.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    public function down(): void {}
};
