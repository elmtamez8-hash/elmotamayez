<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ **لا فهرسَ على `class_sessions` يبدأُ بـ`course_id`، وسؤالٌ جديدٌ صارَ
 * يسألُ به.**
 *
 * `Course::classSessions()` تُقرَأُ بـ`withExists`/`loadExists` لتُجيبَ «هل لهذا
 * الكورسِ حصّةٌ حيّة؟» — على صفحةِ كلِّ طالبٍ وفي فهرسِ كورساتِ كلِّ مدرّس.
 * والعلاقةُ تتجاوزُ نطاقَ المساحةِ عمداً (سياقُ الطالبِ ليسَ مساحةَ الكورس)،
 * فيبقى الشرطُ `course_id = ?` وحدَه — ولا فهرسَ يخدمُه:
 *
 *   PRIMARY · uuid · (workspace_id) · (workspace_id, teacher_profile_id, starts_at)
 *   · (workspace_id, status, starts_at) · (starts_at) · (status, ends_at)
 *   · (charged_at, workspace_id) · (workspace_id, course_id, cohort_id, starts_at)
 *   · (cohort_id, starts_at)
 *
 * كلُّها تبدأُ بعمودٍ آخَر. فالخطّةُ `DEPENDENT SUBQUERY` بـ`type: ALL` — مسحٌ
 * كاملٌ للجدول، وأسوأُ حالاتِه كورسٌ **بلا** حصص، حيثُ لا يستطيعُ `EXISTS` أن
 * يقطعَ مبكِّراً. وذلكَ أكثرُ الكورساتِ عدداً.
 *
 * ⚠️ **والاسمُ مكتوبٌ باليدِ لا مولَّد**: MySQL يرفضُ أيَّ معرّفٍ يتجاوزُ ٦٤
 * حرفاً وSQLite لا حدَّ عندَه، فالنشرُ هو أوّلُ من يراه — وقد أوقفَ إصداراً
 * كاملاً في ٢٠٢٦-٠٩-١٤. هذا ٢٩ حرفاً.
 */
return new class extends Migration
{
    private const INDEX = 'class_sessions_course_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('class_sessions') || Schema::hasIndex('class_sessions', self::INDEX)) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->index('course_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('class_sessions') || ! Schema::hasIndex('class_sessions', self::INDEX)) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
