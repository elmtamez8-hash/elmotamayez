<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٢٦ · T001 — «لمن هذا العنصر».
|
| ⛔ **غيابُ الصفِّ هو «للجميع».** فلا عمودَ بقيمةٍ مبدئيّةٍ على `lessons`، ولا
| ترحيلَ بيانات، ولا حالةَ ثالثة — وFR-002 («لا يتغيّرُ شيءٌ لأحدٍ بمجرّدِ
| الشحن») صحيحةٌ بالبناءِ لا بالحراسة: الجدولُ يُولَدُ فارغاً، فكلُّ عنصرٍ على
| الشجرةِ اليومَ يبقى للجميع.
|
| ⚠️ **والأسماءُ مكتوبةٌ بيدٍ وإن كانَ المولَّدُ يسَعُ اليوم.** أطولُ مولَّدٍ هنا
| `lesson_cohort_scopes_lesson_id_cohort_id_unique` (٤٦ محرفاً) وهو دونَ سقفِ
| MySQL البالغِ ٦٤ — لكنّ الأسماءَ تُقصَّرُ هنا لأنّ اسماً مكتوباً هو اسمٌ يُقرَأُ
| في `show create table` وفي هجرةِ إصلاحٍ لاحقة، والمولَّدُ يتغيّرُ بتغيّرِ
| الأعمدة. وقد أوقفَ تجاوزُ السقفِ نشرةً كاملةً في ٢٠٢٦-٠٩-١٤، وSQLite لا سقفَ
| لها فلا اختبارَ محلّيٌّ يرى ذلك.
|
| ⚠️ **ولا مفتاحَ أجنبيٌّ على `cohort_id`** — اتّساقاً مع الجداولِ المجاورةِ في
| هذا المستودع. ومجموعةٌ أُرشِفَت أو حُذِفَت لا تحتاجُه: **وجودُ صفٍّ** هو
| الشرطُ لا صلاحيّةُ ما يشيرُ إليه، فالعنصرُ يبقى خارجَ مقامِ كلِّ طالبٍ
| (FR-013أ) ولا يقعُ في مقامِ أحدٍ أبداً.
*/
return new class extends Migration
{
    private const PAIR = 'lesson_cohort_scopes_pair_unique';

    private const COHORT = 'lesson_cohort_scopes_cohort_index';

    private const WORKSPACE = 'lesson_cohort_scopes_workspace_index';

    public function up(): void
    {
        Schema::create('lesson_cohort_scopes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('cohort_id');
            $table->timestamps();

            // الحارسُ من صفٍّ مكرَّر: تضييقٌ مرّتَينِ على المجموعةِ نفسِها ليسَ
            // تضييقاً مرّتَين.
            $table->unique(['lesson_id', 'cohort_id'], self::PAIR);
            $table->index('cohort_id', self::COHORT);
            $table->index('workspace_id', self::WORKSPACE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_cohort_scopes');
    }
};
