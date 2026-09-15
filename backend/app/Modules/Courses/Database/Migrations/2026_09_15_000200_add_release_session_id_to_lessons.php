<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٢٦ · T002 — «متى يظهرُ هذا العنصر».
|
| **مفرَّغٌ = يظهرُ الآن** (FR-006)، فالشجرةُ القائمةُ لا يتغيّرُ فيها شيء.
|
| ⛔ **وهو ليسَ `class_session_id` ولا يُقرَأُ مكانَه أبداً.** ذاكَ العمودُ يعني
| «هذا الدرسُ **هو** تسجيلُ الحصّة»، يقرؤُه `LessonResource` بمعنى `is_recording`
| ويكتبُه مستمعُ ٠٠٥ وحدَه، ويمنعُه `UpdateLessonRequest` على كلِّ سطحِ تأليفٍ
| لأنّه يقرّرُ أنّ المشاهدةَ بمقعدٍ لا بتسجيل. وهذا العمودُ يقولُ شيئاً آخرَ
| تماماً: **موعدُ الظهور** — درسٌ عاديٌّ ينتظرُ حصّةً تُعقَد. عمودٌ واحدٌ
| بمعنيَين هو الخلطُ الذي يسجّلُه هذا المستودعُ مرّاتٍ (`research.md` · ق-١).
|
| ⚠️ **وليسَ في `$fillable`**: يُكتَبُ من `SaveLessonAudience` وحدَها، وهي التي
| تتحقّقُ أنّ الحصّةَ من كورسِ الدرسِ نفسِه — وإلّا صارَ الإفراجُ رهنَ حصّةٍ لا
| يحضرُها أحدٌ من هؤلاءِ الطلاب.
*/
return new class extends Migration
{
    private const INDEX = 'lessons_release_session_id_index';

    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('release_session_id')->nullable()->after('class_session_id');
            $table->index('release_session_id', self::INDEX);
        });
    }

    /*
    | ⚠️ **الفهرسُ يُسقَطُ أوّلاً وفي جملتِه، وSQLite وحدَها تقولُ ذلك.** MySQL
    | تُسقِطُ فهرسَ العمودِ الواحدِ معَه بلا اعتراض، فـ`dropColumn` وحدَها تبدو
    | صحيحة؛ و`ALTER TABLE … DROP COLUMN` في SQLite **ترفضُ عموداً مفهرَساً** —
    | وكلُّ اختبارٍ في هذا المستودعِ يجري على SQLite في الذاكرة.
    */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('release_session_id');
        });
    }
};
