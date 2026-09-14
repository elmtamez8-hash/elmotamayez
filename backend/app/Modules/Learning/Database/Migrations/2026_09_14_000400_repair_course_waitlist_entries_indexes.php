<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٣٤ — ترميمُ فهارسِ دَورِ الكورس.
|
| ⛔ **فرعُ الإصلاحِ رجّعَ الفهرسَينِ اللذَينِ سقطا، وسكتَ عن الأربعةِ التي سبقتْهما.**
| قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٤ بـ`show create table`: الجدولُ يحملُ `PRIMARY`
| والفريدَ المركَّبَ وفهرسَ القراءةِ **ولا شيءَ غيرَها** — لا `unique(uuid)`، ولا
| فهرساً على `workspace_id` أو `course_id` أو `student_user_id`.
|
| ⚠️ **والسببُ قاعدةٌ تُكتَبُ مرّةً واحدة**: `Schema::create` جملةٌ لكلِّ فهرس،
| فالنشرُ الذي سقطَ عندَ الفريدِ المركَّبِ تركَ جدولاً ناقصاً — و`addIndexes()`
| كُتِبَت لتُعيدَ **ما سقط** لا **ما يجبُ أن يكون**. فرعُ ترميمٍ يُعيدُ مجموعةَ
| الفهارسِ كاملةً أو لا يكونُ ترميماً.
|
| ⚠️ **وأخطرُ الأربعةِ `unique(uuid)`**: المُعرِّفُ العلنيُّ لهذا النموذجِ هو
| `uuid` (`HasUuid` · `getRouteKeyName`)، فبلا قيدٍ فريدٍ عليه لا شيءَ في قاعدةِ
| البياناتِ يمنعُ صفَّينِ بالمُعرِّفِ نفسِه — والقارئُ يأخذُ أيَّهما جاءَ أوّلاً.
|
| ⚠️ **والأسماءُ هنا مولَّدةٌ عمداً**: أطولُها ٤٥ حرفاً
| (`course_waitlist_entries_student_user_id_index`)، دونَ سقفِ MySQL بفارقٍ مريح —
| والمركَّبُ من ثلاثةِ أعمدةٍ وحدَه هو ما تجاوزَه، فلا تُغيَّرُ أسماءُ الأربعةِ
| لتُطابِقَ أسلوبَ جارَتَيها: اسمٌ مكتوبٌ بيدٍ هنا يُنشئُ فهرساً خامساً على قاعدةٍ
| جديدةٍ بدلَ أن يُطابِقَ القائم.
|
| ولا شيءَ يقعُ على قاعدةٍ وُلِدَت سليمةً — كلُّ سطرٍ خلفَ `hasIndex()`.
*/
return new class extends Migration
{
    /** الاسمُ المولَّدُ ⇐ الأعمدة، كما تُعلِنُها هجرةُ الإنشاء. */
    private const MISSING = [
        'course_waitlist_entries_uuid_unique' => ['uuid'],
        'course_waitlist_entries_workspace_id_index' => ['workspace_id'],
        'course_waitlist_entries_course_id_index' => ['course_id'],
        'course_waitlist_entries_student_user_id_index' => ['student_user_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('course_waitlist_entries')) {
            return;
        }

        Schema::table('course_waitlist_entries', function (Blueprint $table): void {
            foreach (self::MISSING as $name => $columns) {
                if (Schema::hasIndex('course_waitlist_entries', $name)) {
                    continue;
                }

                if (str_ends_with($name, '_unique')) {
                    $table->unique($columns, $name);

                    continue;
                }

                $table->index($columns, $name);
            }
        });
    }

    /*
    | ⛔ **لا تراجُعَ.** الهجرةُ تُعيدُ ما كانَ يجبُ أن يُنشَأَ مع الجدول، فإسقاطُه
    | في `down()` يُعيدُ العطبَ نفسَه — وهجرةُ الإنشاءِ هي التي تملكُ إسقاطَ الجدولِ
    | بكلِّ فهارسِه.
    */
    public function down(): void
    {
        //
    }
};
