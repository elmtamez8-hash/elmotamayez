<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 7b. What `exam_answers` needs to carry essays, grading and the notebook.
|
| ⚠️ `uuid` IS NOT COSMETIC HERE — WITHOUT IT THERE IS NO GRADING ROUTE. The
| table shipped with an autoincrement id and no uuid, and `Answer` does not use
| `HasUuid`. `POST /manage/grading/answers/{uuid}` binds to it. The only other
| option is exposing the sequential id, which every route in this product refuses.
|
| ⚠️ `student_user_id` IS DELIBERATELY DUPLICATED (plan.md §Complexity). It is
| derivable through `attempt_id`, but the mistake notebook asks "every wrong
| answer by this student" on the longest page a student reads, and NFR-010
| forbids cost growing with the number of attempts. It is written once at row
| creation and never changes, so there is no drift to manage.
|
| `grading_version` is the claim token for a revision. `graded_at` is the claim
| token for the first grading — both live here rather than on `grading_records`,
| which is append-only and whose `graded_at` is never null, so a
| `WHERE graded_at IS NULL` claim over THAT table would never match a row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->unsignedBigInteger('student_user_id')->nullable()->after('question_id');
            $table->longText('answer_text')->nullable()->after('selected_option_ids');
            $table->boolean('requires_grading')->default(false)->after('points');
            $table->timestamp('graded_at')->nullable()->after('requires_grading');
            $table->unsignedBigInteger('graded_by')->nullable()->after('graded_at');
            $table->unsignedInteger('grading_version')->default(0)->after('graded_by');
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropColumn([
                'uuid', 'student_user_id', 'answer_text',
                'requires_grading', 'graded_at', 'graded_by', 'grading_version',
            ]);
        });
    }
};
