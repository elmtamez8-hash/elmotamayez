<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The student's own photo — on `student_profiles`, not on `users`.
     *
     * `users` carries what every account has; anything true of one role gets its
     * own table. The teacher's photo already lives on `teacher_profiles`
     * (`photo_path`), so the student's belongs here by the same rule, and the
     * column is named to match it.
     *
     * ⚠️ Publishing this on a review is a DECISION, not a default. The reviewer's
     * family name is truncated by `Review::studentDisplayName()` so a teacher
     * cannot identify who rated them (FR-021), and a face beside that truncation
     * undoes it. The product owner asked for the photo on the teacher's reviews
     * tab with that trade-off stated; `PublicFieldAllowlist::REVIEW` records it.
     * Nothing here forces the field into a payload — a new public surface that
     * wants it makes the same decision again.
     *
     * Nullable and it stays nullable: an avatar is something a student may never
     * upload, and initials are the designed fallback, not an error state.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
