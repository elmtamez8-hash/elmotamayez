<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teacher_applications.user_id` تحملُ فهرسَينِ منذُ يومِ إنشائِها.
 *
 * ⛔ **والسطرانِ في المايجريشنِ نفسِه، على بُعدِ عشرينَ سطراً.**
 * `_2026_08_01_000600_create_teacher_applications_table` يكتبُ
 * `unsignedBigInteger('user_id')->index()` في تعريفِ العمود، ثمّ
 * `unique('user_id')` في آخرِ الجدولِ تحتَ تعليقٍ يشرحُ القاعدةَ («طلبٌ حيٌّ
 * واحدٌ لكلِّ مستخدم»). كلُّ نصفٍ صحيحٌ وحدَه، والاثنانِ معاً شجرتانِ على عمودٍ
 * واحدٍ تُكتَبانِ مع كلِّ طلبِ انضمامٍ وتُقرَأُ إحداهما فقط.
 *
 * ⚠️ **ولم يجدْه أحدٌ بالقراءة**: وُجِدَ يومَ كُتِبَ `SchemaIndexHygieneTest`
 * للحالةِ الأخرى (`credit_purchases.order_id`)، فأضاءَ هذه معها. وهي فائدةُ
 * حارسٍ على المخطَّطِ كلِّه بدلَ توكيدٍ على الجدولِ الذي تعثّرَ فيه أحدُهم.
 *
 * ⚠️ **والزائدُ هو العاديُّ لا الفريد**: الفريدُ يخدمُ كلَّ قراءةٍ يخدمُها
 * العاديُّ ويزيدُ عليها قاعدةً يحرسُها المحرّك.
 *
 * ⚠️ **ومشروطٌ بـ`hasIndex()`** — إسقاطُ فهرسٍ غائبٍ يُوقِفُ النشرةَ ويُسقِطُ
 * معها كلَّ مايجريشنٍ خلفَها.
 *
 * ولا يُكتَبُ صفٌّ ولا يُعدَّل.
 */
return new class extends Migration
{
    private const INDEX = 'teacher_applications_user_id_index';

    public function up(): void
    {
        if (! Schema::hasIndex('teacher_applications', self::INDEX)) {
            return;
        }

        Schema::table('teacher_applications', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /** تُعيدُ الزائدَ عمداً — التراجعُ يصفُ الحالَ كما كانت. */
    public function down(): void
    {
        if (Schema::hasIndex('teacher_applications', self::INDEX)) {
            return;
        }

        Schema::table('teacher_applications', function (Blueprint $table): void {
            $table->index('user_id', self::INDEX);
        });
    }
};
