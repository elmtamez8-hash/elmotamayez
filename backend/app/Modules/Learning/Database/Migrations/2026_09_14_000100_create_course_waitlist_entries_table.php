<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ٠٣٤ · T042 — دَورُ الكورسِ المكتمل (FR-026 … FR-029).
|
| ⚠️ `closed_slot` هو الفرقُ بينَ حارسٍ يعضُّ ولا حارسَ إطلاقاً — والإملاءُ
| البديهيُّ خطآنِ في واحد: فهرسٌ جزئيٌّ مشروطٌ بـ`closed_at IS NULL` ميزةُ
| Postgres **لا وجودَ لها على MySQL**، وفهرسٌ فريدٌ يحملُ عموداً قابلاً للعدمِ
| **لا يعضُّ أبداً** لأنّ `NULL` لا تساوي `NULL`. فالصفرُ سنتينل: صفرٌ ما دامَ
| الصفُّ قائماً، ومعرِّفُ الصفِّ نفسُه حينَ يُختَم — فريدٌ بالتعريف. وسابقتُه في
| هذه الشجرةِ أربع: `cohort_memberships.closed_slot` · `concept_stats.lesson_id`
| · `unlock_rules.course_id` · `award_entries.reversal_of_id`.
|
| ⚠️ **والفهرسُ الثاني ليسَ زينة.** الفريدُ يبدأُ بالطالب، وقراءةُ الشاشةِ تبدأُ
| بالكورسِ وتُرتِّبُ بالوقت — فبلا `(course_id, closed_slot, created_at)` هي مسحُ
| جدولٍ وفرزٌ في الذاكرةِ على الجدولِ الأسرعِ نموّاً في هذه المرحلة.
|
| ⚠️ **ولا عمودَ `position`.** رقمُ موضعٍ مخزَّنٌ يحتاجُ إعادةَ ترقيمِ كلِّ صفٍّ
| بعدَه عندَ كلِّ خروج — والخروجُ يقعُ عندَ كلِّ تسجيلٍ جديد (FR-028). والترتيبُ
| مشتقٌّ من `created_at` ثمّ المعرِّف، ويُرسَمُ الرقمُ من فهرسِ الصفِّ في الصفحة.
|
| ⚠️ **و`workspace_id` عمودٌ يُكتَبُ من الكورسِ صراحةً**: صاحبُ الصفِّ طالبٌ عضوٌ
| في لا مساحة، فسياقُه `null` دائماً والملءُ التلقائيُّ لا يقعُ أبداً. والتصنيفُ
| هنا للوحةِ الإدارةِ لا لحراسةِ الطالب — حارسُه شرطٌ صريحٌ بصاحبِ الصفّ.
*/
return new class extends Migration
{
    /** ٣٠ حرفاً وَ٢٩ — والمولَّدُ منهما ٦٨ وَ٦٢. */
    private const UNIQUE = 'cwe_student_course_slot_unique';

    private const LOOKUP = 'cwe_course_slot_created_index';

    /*
    | ⛔ **واسمُ الفهرسِ يُكتَبُ بيدٍ، لأنّ المولَّدَ ٦٨ حرفاً وسقفُ MySQL ٦٤ —
    | وSQLite لا سقفَ له، فلا اختبارٌ محلّيٌّ يرى ذلك.** قِيسَ على الإنتاجِ
    | ٢٠٢٦-٠٩-١٤: `ERROR 1059 Identifier name … is too long` على
    | `course_waitlist_entries_student_user_id_course_id_closed_slot_unique`،
    | فسقطَ النشرُ كلُّه ولم تصلْ هجرتا الردمِ بعدَه.
    |
    | ⚠️ **و`Schema::create` ليست جملةً واحدة**: CREATE TABLE أوّلاً ثمّ جملةٌ
    | لكلِّ فهرس — فالجدولُ **أُنشِئَ فعلاً** على الإنتاجِ ثمّ سقطَ الفهرسُ
    | الفريدُ وما بعدَه، ولم يُسجَّلْ صفٌّ في `migrations`. فهذه الهجرةُ تُعيدُ
    | التشغيلَ على قاعدةٍ فيها الجدولُ ناقصَ فهرسَين، ولا يجوزُ أن تُسقِطَه:
    | صفوفُه — إن وُجِدَت — كُتِبَت بينَ بدءِ الحاوياتِ وفشلِ الهجرة.
    */
    public function up(): void
    {
        if (Schema::hasTable('course_waitlist_entries')) {
            $this->addIndexes();

            return;
        }

        Schema::create('course_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            // مَن ضغطَ الزرّ: الطالبُ نفسُه أو وليُّه (FR-026أ). يُحفَظُ لأنّ
            // «مَن سجَّلَ ابني في الدَّور» سؤالٌ يُسأَلُ بعدَ شهر.
            $table->unsignedBigInteger('registered_by_user_id')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_slot')->default(0);
            $table->timestamps();

            $table->unique(['student_user_id', 'course_id', 'closed_slot'], self::UNIQUE);
            $table->index(['course_id', 'closed_slot', 'created_at'], self::LOOKUP);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_waitlist_entries');
    }

    /** الإصلاحُ: جدولٌ موجودٌ من نشرٍ سقطَ قبلَ فهرسَيه. */
    private function addIndexes(): void
    {
        Schema::table('course_waitlist_entries', function (Blueprint $table): void {
            if (! Schema::hasIndex('course_waitlist_entries', self::UNIQUE)) {
                $table->unique(['student_user_id', 'course_id', 'closed_slot'], self::UNIQUE);
            }

            if (! Schema::hasIndex('course_waitlist_entries', self::LOOKUP)) {
                $table->index(['course_id', 'closed_slot', 'created_at'], self::LOOKUP);
            }
        });
    }
};
