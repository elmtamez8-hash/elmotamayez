<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 7f. What the attempt needs for essays and for practice.
|
| ⚠️ `exam_id` BECOMES NULLABLE, and that is the cheaper of two bad options. A
| self-generated practice run is not an exam anybody authored: the alternatives
| were a row in `exams` per generation — a STUDENT writing into a table the
| teacher owns, and their exam list filling with machine noise — or a sentinel
| exam per workspace, which is the same thing wearing a disguise.
|
| `is_practice` sits on the ATTEMPT, not on the exam, because the same exam can
| legitimately be sat both ways: officially once, and again for revision.
|
| `pending_grading` is a status rather than a flag on a "graded" boolean, because
| a flag makes the condition optional for every reader — and the one reader that
| must not treat it as optional is the certificate listener.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('submitted_at');
            $table->boolean('is_practice')->default(false)->after('status');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('exam_id')->nullable()->change();
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            // The grading queue filters on status and orders by submission time,
            // and neither column is in the shipped index.
            $table->index(['workspace_id', 'status', 'submitted_at'], 'exam_attempts_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('exam_attempts_queue_index');
            $table->dropColumn(['finalized_at', 'is_practice']);
        });

        // `exam_id` is deliberately NOT restored to NOT NULL: practice attempts
        // written while this migration was applied hold null, and a rollback that
        // destroys rows to satisfy a constraint is worse than a schema that
        // admits it cannot fully reverse.
    }
};
