<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 021 · T044 — the transfer request (FR-028هـ … FR-028ح).
|
| ⚠️ `pending_slot` IS `closed_slot`'S GUARD, WORD FOR WORD: `0` while pending,
| the row's own id once decided. "One pending request per (student, course)"
| (FR-028ز) is a unique index or it is nothing — NULL never equals NULL, and
| MySQL has no partial index.
|
| ⚠️ NOTHING HERE TOUCHES `cohort_memberships` (FR-028و). The student stays in
| their group with every right intact until the moment of approval — otherwise
| they leave one place before entering another, waiting on an answer that may
| never come. And capacity is measured AT APPROVAL, not here: two pending
| requests on one seat is the definition of the race.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('to_cohort_id')->index();
            $table->unsignedBigInteger('from_cohort_id')->nullable();
            $table->string('student_reason', 500)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            // Mandatory on a rejection (FR-028ح) — enforced in the Action, which
            // is the entry point the panel and the API share. A silent refusal
            // reads as a fault and is asked for again for ever.
            $table->string('decision_reason', 500)->nullable();
            $table->unsignedBigInteger('pending_slot')->default(0);
            $table->timestamps();

            $table->unique(['student_user_id', 'course_id', 'pending_slot']);
            $table->index(['to_cohort_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_transfer_requests');
    }
};
