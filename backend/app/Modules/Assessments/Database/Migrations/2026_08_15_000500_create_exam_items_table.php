<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Step 5. The join table, and its contents, built from the column that is about
| to be removed — while that column still holds the answer.
|
| This is the whole redirect. `exam_answers.question_id` points at `questions.id`
| in every attempt recorded since spec 003; building a NEW bank table and moving
| rows into it would mean either breaking that key or rewriting every answer row
| on the platform inside a migration. SC-015 asks for 100% of past attempts to
| survive, and this gives it by NOT TOUCHING A SINGLE ANSWER ROW — not because
| the migration is careful, but because it does not happen.
|
| Dropping `questions.exam_id` is step 6, and it ships in a LATER DEPLOY —
| `2026_08_26_000600_drop_exam_id_from_questions.php`, nine specs after this one.
| See that file for why the gap is the point rather than an oversight.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('question_id');
            $table->unsignedSmallInteger('order')->default(0);
            // Null means "worth what the bank says". A question can be worth more
            // in a final than in a quiz without the bank row changing.
            $table->unsignedSmallInteger('points_override')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'question_id']);
            // "Which exams use this question?" — asked before every disable, and
            // on the bank browse screen next to every row.
            $table->index('question_id');
        });

        $now = now();

        DB::table('questions')
            ->select('id', 'workspace_id', 'exam_id')
            ->whereNotNull('exam_id')
            ->orderBy('id')
            ->chunkById(500, function ($questions) use ($now): void {
                $rows = [];

                foreach ($questions as $question) {
                    $rows[] = [
                        'uuid' => (string) Str::uuid(),
                        'workspace_id' => $question->workspace_id,
                        'exam_id' => $question->exam_id,
                        'question_id' => $question->id,
                        'order' => 0,
                        'points_override' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('exam_items')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_items');
    }
};
