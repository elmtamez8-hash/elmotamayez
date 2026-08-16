<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 11. What earns the next session, and who is let past it anyway.
|
| ⚠️ `course_id` IS NOT NULL AND ZERO MEANS "THE WORKSPACE DEFAULT". A nullable
| column here would be the `concept_stats.lesson_id` defect a second time: NULL
| never equals NULL, so `unique(workspace_id, course_id)` would not bite on the
| one row every workspace has — the default. Two teachers saving their default
| twice would get two rows, and «which rule applies» would have two answers with
| nothing to choose between them. The sentinel makes the index real, and makes
| the whole lookup one `whereIn('course_id', [0, $courseId])`.
|
| ⚠️ AND THERE IS NO PER-SESSION LEVEL. Two tiers — workspace and course — are
| what FR-037 asks for, and a third would make the precedence chain something a
| teacher has to hold in their head before they can predict what a student sees.
|
| ⚠️ THE COMPONENTS ARE COLUMNS, NOT A JSON BLOB. The resolver reads them, the
| eligibility payload names which one failed (FR-038), and the roles screen
| offers them as tick boxes; a blob would make every one of those a string
| comparison nobody validates.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unlock_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            // 0 = this workspace's default. See the note above.
            $table->unsignedBigInteger('course_id')->default(0);
            $table->boolean('requires_attendance')->default(false);
            $table->boolean('requires_assignment')->default(false);
            // Only consulted when `requires_assignment` is true. Zero means
            // "handing it in is enough" — a distinct rule from 50٪, and the one
            // most teachers actually want.
            $table->decimal('min_score_pct', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'course_id']);
        });

        Schema::create('unlock_exemptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_user_id');
            // FR-040 asks for both, and the reason is what makes the record worth
            // keeping: an exemption with no stated cause is indistinguishable
            // from a mistake six months later.
            $table->string('reason');
            $table->unsignedBigInteger('granted_by');
            $table->timestamps();

            // One exemption per student per session. A second row would be a
            // second answer to a yes/no question.
            $table->unique(['class_session_id', 'student_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unlock_exemptions');
        Schema::dropIfExists('unlock_rules');
    }
};
