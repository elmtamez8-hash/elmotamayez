<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسئلةُ المدرّسِ الشائعةُ وفيديوُه التعريفيّ.
 *
 * ⚠️ **`faqs` كانَ `[]` مكتوباً حرفيّاً في `PublicTeacherDetailResource`** — لا
 * عمودَ ولا كاتبَ ولا صفَّ واحد. فالقائمةُ العامّةُ تحملُ المفتاحَ منذُ ٠٠٢،
 * و`FaqAccordion` مرسومٌ على الصفحةِ خلفَ `length > 0`، فلم يُعرَضْ قطُّ ولم يفشلْ
 * شيء. عائلةُ «نقطةُ نهايةٍ لا يناديها أحد» بعينِها، من الجهةِ المقابلة: حقلٌ له
 * قارئٌ وبلا مخزَن.
 *
 * وعمودُ JSON لا جدولٌ ثانٍ: القائمةُ مملوكةٌ لملفٍّ واحد، مرتَّبةٌ كما كتبَها
 * صاحبُها، ولا يُستعلَمُ عن سؤالٍ منها وحدَه أبداً — وهو بعينِه سببُ كونِ
 * `qualifications` عموداً منذُ ٠٠١.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->json('faqs')->nullable()->after('qualifications');
            // رابطٌ لا ملفّ: لا مسارَ رفعٍ ولا قرارَ تخزينٍ ولا فاتورةَ ترميز.
            $table->string('intro_video_url', 500)->nullable()->after('faqs');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn(['faqs', 'intro_video_url']);
        });
    }
};
