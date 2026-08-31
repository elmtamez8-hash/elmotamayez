<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012 · T085 — the paper, frozen once at creation.
 *
 * ⚠️ FROZEN AT CREATION AND NOT AT EACH JOIN, BECAUSE THE STORY SAYS «THE SAME
 * SET AT THE SAME TIME». A set chosen when each participant arrives is a
 * different paper per person wearing one room's name, and the board comparing
 * them would be comparing nothing.
 *
 * The snapshot is `attempt_items`'s rule reached from a second direction: a
 * question edited after the room opened does not change what the people inside
 * it were shown.
 *
 * ⚠️ NO `uuid`. The row is never addressed from outside — the payload carries
 * `order` and the content — and a uuid column nobody reads is a column somebody
 * later tries to route on.
 *
 * ⚠️ AND `points` IS CAPPED IN THE ACTION. `study_room_participants.score` is an
 * `unsignedSmallInteger` (65535) holding the sum over at most thirty questions,
 * so a single question worth a thousand points overflows it — strict MySQL
 * refuses the row and SQLite truncates in silence, which is the worse of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_room_questions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('study_room_id');
            $table->unsignedBigInteger('question_id');

            $table->unsignedTinyInteger('order');
            $table->unsignedSmallInteger('points')->default(1);
            $table->json('snapshot');

            $table->timestamps();

            $table->unique(['study_room_id', 'order']);
            // One question cannot appear twice on one paper: the board's
            // denominator is a row count, so a duplicate would make a set of ten
            // that can never be finished.
            $table->unique(['study_room_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_room_questions');
    }
};
