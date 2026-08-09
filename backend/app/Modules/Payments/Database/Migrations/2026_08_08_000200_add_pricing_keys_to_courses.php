<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three keys that let one function price both sides of the same session.
 *
 * Q-7 makes the course the pricing context: a student never books in the
 * abstract, they book sessions of a known course. For the purchase price and the
 * settlement rate to agree by construction rather than by luck, both must be
 * resolved from the SAME inputs — and Settlement\Support\RateResolver takes a
 * teacher profile, a subject and a grade level. `courses` had none of them.
 *
 * teacher_profile_id is the one without which the contract is not implementable
 * at all: RateResolver starts from that key, and `courses` carries only
 * `created_by`, which is nullable — the model's own comment says the course may
 * outlive its author.
 *
 * Lives under Payments rather than Courses because it exists for the billing
 * engine; the columns are read by CostPlusPricing and by the settlement rate
 * lookup, not by course authoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->nullable()->after('description');
            $table->string('grade_level', 32)->nullable()->after('subject_id');
            $table->unsignedBigInteger('teacher_profile_id')->nullable()->after('grade_level');

            $table->index('subject_id');
            $table->index('teacher_profile_id');
        });

        $this->backfillTeacherProfile();
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['teacher_profile_id']);
            $table->dropIndex(['subject_id']);
            $table->dropColumn(['subject_id', 'grade_level', 'teacher_profile_id']);
        });
    }

    /**
     * Point every existing course at its workspace's teacher profile.
     *
     * From the WORKSPACE, not from `created_by`: a course created by an assistant
     * still sells at the teacher's rate, and `created_by` is nullable anyway.
     * `teacher_profiles.user_id` is globally unique, so a workspace holds at most
     * one — where it holds none, the column stays null and the packages endpoint
     * returns an empty list rather than a wrong price.
     *
     * chunkById, never chunk: the predicate shrinks as rows are fixed, and OFFSET
     * paging under a shrinking predicate skips as many rows per page as the
     * previous page repaired — and reports success. Same lesson as the uuid
     * backfill in 016.
     *
     * No `UPDATE ... JOIN`: MySQL and SQLite disagree on its syntax.
     */
    private function backfillTeacherProfile(): void
    {
        $profiles = DB::table('teacher_profiles')
            ->select('workspace_id', 'id')
            ->get()
            ->keyBy('workspace_id');

        if ($profiles->isEmpty()) {
            return;
        }

        DB::table('courses')
            ->whereNull('teacher_profile_id')
            ->select('id', 'workspace_id')
            ->chunkById(500, function ($courses) use ($profiles): void {
                foreach ($courses as $course) {
                    $profile = $profiles->get($course->workspace_id);

                    if ($profile === null) {
                        continue;
                    }

                    DB::table('courses')
                        ->where('id', $course->id)
                        ->update(['teacher_profile_id' => $profile->id]);
                }
            });
    }
};
