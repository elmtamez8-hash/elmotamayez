<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٣٦ · T001 — الشكلُ الثاني للباقة: بالحصصِ بدلَ المدّة.
|
| ⚠️ **اختياريٌّ لسببَين، وكلاهما يمنعُ «NOT NULL» هنا.**
|
| الأوّلُ عن البيانات: كلُّ صفٍّ في `plans` اليومَ باقةُ مدّةٍ **بحقّ** — لا قيمةَ
| صحيحةً لعددِ حصصِه، و«صفرٌ» ليست «لا ينطبق» بل رقمٌ يقرؤُه أوّلُ استعلامٍ
| كأنّه باقةٌ بلا حصص.
|
| والثاني عن المحرّك: `ADD COLUMN NOT NULL` بلا قيمةٍ افتراضيّةٍ **ترفضُه
| SQLite**، وكلُّ اختبارٍ في هذا المستودَعِ يعملُ عليها — فالشكلُ الوحيدُ الذي
| يمرُّ في الاختباراتِ هو الشكلُ الصحيحُ على كلِّ حال.
|
| ⚠️ **وقاعدةُ «أحدُ الشكلَينِ لا كلاهما ولا لا شيء» تُفرَضُ في `SavePlan`، لا
| هنا.** المحرّكُ يستطيعُ أن يقولَ «قد يكونُ فارغاً» ولا يستطيعُ أن يقولَ «أحدُ
| هذَين بالضبط» — قيدُ `CHECK` مختلفٌ بينَ المحرّكَين ولا يقولُ للمدرّسِ ما الخطأ.
| فالحارسُ في الـAction حيثُ يُقرَأُ بجوارِ الرسالةِ التي تُعرَض (data-model.md).
|
| ⚠️ **ولا فهرسَ الآن** (T006): يُشتَقُّ من استعلامِ الغَلَبةِ بعدَ كتابتِه، وباسمٍ
| مكتوبٍ بيدٍ — الشكلُ التلقائيُّ هنا يتجاوزُ حدَّ MySQL (٦٤ محرفاً) ولا تعرفُ
| SQLite ذلك الحدَّ أصلاً، فلا شريحةَ من CI تراه.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('session_count')->nullable()->after('duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('session_count');
        });
    }
};
