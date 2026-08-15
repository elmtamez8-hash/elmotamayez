<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 4. The constraints, now that step 3 has made the data eligible for them.
|
| ⚠️ THE ORDER INSIDE THIS FILE MATTERS AS MUCH AS THE ORDER BETWEEN FILES.
| Making an existing column NOT NULL REBUILDS THE TABLE on SQLite — Doctrine
| copies rows into a new table and swaps it. A table rebuilt AFTER an index was
| created is a table that can quietly come back without it, which is why 016's
| uuid migration refused the conversion outright. Here the conversion runs FIRST
| and the unique indexes are created on the settled table, so nothing a rebuild
| does can drop them.
|
| ⚠️ AND THIS STEP NEEDS THE NEW CODE DEPLOYED FIRST. Between step 3 finishing
| and this file running, the old `SaveQuestion` is still accepting requests and
| still knows nothing about `concept_id`. One question created in that window
| makes this migration fail on production, halfway through the chain.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unsignedBigInteger('concept_id')->nullable(false)->change();
            $table->string('bloom_level')->nullable(false)->change();
            $table->string('content_hash', 64)->nullable(false)->change();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->unique('uuid');
            /*
             | ⚠️ PLAIN, NOT UNIQUE — and live data is what settled it.
             |
             | The design said the import's "skip" policy should be a database
             | constraint, because a lookup followed by an insert is the race two
             | browser tabs win together. Running the chain against a populated
             | database refused it: four groups of questions in one workspace
             | already share their text, which is exactly the duplication the bank
             | exists to STOP creating — every one of them authored separately
             | into a different exam back when a question belonged to one.
             |
             | Those rows cannot be merged away. `exam_answers.question_id` points
             | at each of them from real attempts, so collapsing duplicates means
             | either orphaning answers or rewriting them — and not rewriting a
             | single answer row is the entire reason the redirect was chosen over
             | a new table (SC-015).
             |
             | So the hash keeps its index for lookup speed, and the race it was
             | meant to close is closed where it actually occurs instead: two
             | concurrent imports by one teacher. `ImportQuestionsJob` carries
             | `WithoutOverlapping` keyed by workspace, so they queue rather than
             | interleave. A constraint the existing rows cannot satisfy is not a
             | stricter guarantee — it is a deploy that fails.
             */
            $table->index(['workspace_id', 'content_hash']);
            $table->index(['workspace_id', 'concept_id', 'difficulty']);
            $table->index(['workspace_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropIndex(['workspace_id', 'content_hash']);
            $table->dropIndex(['workspace_id', 'concept_id', 'difficulty']);
            $table->dropIndex(['workspace_id', 'lesson_id']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->change();
            $table->unsignedBigInteger('concept_id')->nullable()->change();
            $table->string('bloom_level')->nullable()->change();
            $table->string('content_hash', 64)->nullable()->change();
        });
    }
};
