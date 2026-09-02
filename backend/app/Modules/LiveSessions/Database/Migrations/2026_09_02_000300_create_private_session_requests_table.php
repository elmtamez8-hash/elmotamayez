<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 023 · T026 — «أريد حصّة خاصّة يوم الثلاثاء ٦م» (FR-015 … FR-026).
|
| Nothing here is a session and nothing here is money. FR-017 is the whole
| reason the table exists: a request costs the student nothing and holds nothing
| of the teacher's, so it can be refused at no cost to either — which is what
| makes «موافقة إنسان» a real decision rather than a formality after the fact.
|
| ⚠️ `pending_slot` IS `closed_slot`'S GUARD, WORD FOR WORD — the same one
| `cohort_transfer_requests` carries: `0` while the request is live, the row's
| own id once it is decided. «فريدٌ لكلِّ (طالب × لحظةِ بداية) ما دامَ قائماً»
| (FR-022) is a unique index or it is nothing.
|
| The design named a nullable `pending_key` holding `student_user_id:starts_at`.
| It is the SAME guard, and this spelling was chosen instead because it needs no
| denormalised second copy of two columns that are already here — a string that
| drifts from them at the first change of timestamp format. Both work on both
| engines; only this one is already in the tree.
|
| ⚠️ AND A PARTIAL INDEX (`WHERE status = 'pending'`) IS NOT THE ANSWER, however
| obviously it reads. SQLite has had them since 3.8 and **MySQL has none at all**
| — so that spelling is green in every local run of this suite and kills the
| migration on the deploy.
|
| ⚠️ THE INDEX NAME IS EXPLICIT because the generated one
| (`private_session_requests_student_user_id_starts_at_pending_slot_unique`, 71
| characters) exceeds MySQL's 64-character identifier cap — error 1059. SQLite
| has no cap, so this too is invisible until production.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_session_requests', function (Blueprint $table) {
            $table->id();
            // Context, not a guard. The request is a bridge like `Enrollment`:
            // the student is a member of no workspace, so `WorkspaceScope` adds
            // no condition for them and the real guards are the explicit
            // ownership filter and the policy.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');
            // An absolute instant (FR-016). Never a local wall-clock string: the
            // teacher and the student may not be in one timezone, and a string
            // is an hour wrong twice a year with nothing to say so.
            $table->timestamp('starts_at');
            // Copied from the course AT REQUEST TIME, the `subject_id` reasoning:
            // a teacher who lengthens their lessons next term must not silently
            // relengthen a request already sitting in their queue.
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 16)->default('pending');
            // The teacher's own words on a refusal — read by the student, which
            // is the whole of FR-018. A silent refusal reads as a fault and is
            // submitted again for ever.
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('class_session_id')->nullable()->unique();
            $table->unsignedBigInteger('pending_slot')->default(0);
            $table->timestamps();

            $table->unique(['student_user_id', 'starts_at', 'pending_slot'], 'psr_pending_unique');
            // The teacher's queue.
            $table->index(['teacher_profile_id', 'status']);
            // The expiry sweep.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_session_requests');
    }
};
