<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 049 — «أجّل حصّةَ هذا الأسبوع، والتي بعدَها في موعدِها».
|
| ⚠️ «مؤقّتة» تحتاجُ لا عمودَ ولا علَماً ولا آليّةً من أيِّ نوع. الحصصُ صفوفٌ لها
| تواريخُها منذُ ٠٤٨ — لا مراجعَ إلى شقوقِ توفّرٍ أسبوعيّة — فتأجيلُ واحدةٍ تعديلُ
| صفٍّ واحد، والحصّةُ التاليةُ صفٌّ آخرُ لم يمسَسْه شيء. «التالية في موعدِها
| الطبيعيّ» خاصّيّةُ النموذجِ لا قاعدةٌ تُنفَّذ.
|
| ⚠️ `pending_slot` هو حارسُ `closed_slot` حرفاً بحرف — كما في
| `private_session_requests` و`cohort_transfer_requests`: صفرٌ ما دامَ الطلبُ
| قائماً، ومعرِّفُ الصفِّ نفسِه بعدَ البتّ. «طلبٌ واحدٌ قائمٌ لكلِّ حصّة» فهرسٌ فريدٌ
| أو لا شيء — فـNULL لا يساوي NULL، ولا فهارسَ جزئيّةَ في MySQL أصلاً.
|
| ⚠️ والوقتانِ **كلاهما** محفوظان. بعدَ الموافقةِ يصيرُ موعدُ الحصّةِ الجديدَ هو
| الموعد، فالقديمُ لا يبقى في أيِّ مكانٍ آخر — والإشعارُ يقولُ «من كذا إلى كذا»
| أو لا يقولُ شيئاً مفيداً.
|
| ⚠️ واسمُ الفهرسِ صريحٌ لأنّ المُولَّدَ يتجاوزُ ٦٤ حرفاً — سقفَ MySQL للمعرِّفات
| (خطأ 1059) — و**SQLite بلا سقفٍ إطلاقاً**، فالمُولَّدُ أخضرُ في كلِّ تشغيلةِ
| اختبارٍ ويسقطُ في أوّلِ هجرةٍ على الإنتاج.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_reschedule_requests', function (Blueprint $table) {
            $table->id();
            // Context, not a guard. The asker is a student, and a student is a
            // member of no workspace — so `WorkspaceScope` adds no condition for
            // them and the real guards are the explicit filter and the policy.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('class_session_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            // Absolute instants, never a local wall-clock string: the teacher and
            // the student may not share a timezone, and a string is an hour wrong
            // twice a year with nothing to say so.
            $table->timestamp('from_starts_at');
            $table->timestamp('to_starts_at');
            $table->string('student_reason', 500)->nullable();
            $table->string('status', 16)->default('pending');
            // Mandatory on a rejection, enforced in the Action — the entry point
            // the panel and the API share. A silent refusal reads as a fault and
            // is asked for again for ever.
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('pending_slot')->default(0);
            $table->timestamps();

            $table->unique(['class_session_id', 'pending_slot'], 'srr_pending_unique');
            // The teacher's queue.
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_reschedule_requests');
    }
};
