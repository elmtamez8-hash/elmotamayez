<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 7a. What the student SAW, written when the attempt starts.
|
| The score is already preserved today — `exam_answers` stores `is_correct` and
| `points` at grading time. What is stored nowhere is the question as it was: a
| teacher who deletes an option after the fact makes the review of that attempt
| impossible to render. FR-004 forbids an edit from changing what a past sitter
| saw, and a row per question per attempt is the only place that fact can live.
|
| ⚠️ AT START, NOT AT SUBMIT. "What they saw" is literally defined at the moment
| the paper is handed over; an edit made while someone is mid-attempt does not
| change the paper they already have.
|
| And it closes a second hole for free: the denominator today is computed from
| the exam's LIVE questions at grading time, so deleting a question between start
| and submit moves the total under the student. From here, the denominator is
| what was shown.
|
| ⚠️ REJECTED: `question_versions`, a row per edit. That grows with how often
| teachers tidy their wording, whether or not anyone ever sat the question. A
| snapshot is needed only where an attempt exists.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('question_id')->index();
            $table->unsignedSmallInteger('order')->default(0);
            // Its worth in THIS exam at the moment of starting — override or
            // bank value, already resolved.
            $table->unsignedSmallInteger('points')->default(1);
            // text · options and their order · the correct set.
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_items');
    }
};
