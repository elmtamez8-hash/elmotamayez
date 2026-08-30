<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 022 · FR-001ب — the individual school year a STUDENT picks.
 *
 * ⚠️ A SECOND VOCABULARY, NOT A REPLACEMENT FOR `grade_levels`. A teacher says
 * "I teach secondary"; a student says "I am in year 10". Folding them into one
 * table would either make the teacher tick twelve boxes or make the student
 * answer a question they do not think in — and the broad slugs cannot move
 * anyway: `courses.grade_level` is undefended text carrying them, the
 * leaderboard key is `grade:{slug}`, and a teacher's settlement rate is keyed on
 * `(subject, grade_level)`, which is a money path.
 *
 * ⚠️ NO `workspace_id`. Constitution §I, platform reference data (ب): read
 * publicly, written with the platform permission `taxonomy.manage`. Adding
 * `BelongsToWorkspace` here would silently duplicate "year 10" once per teacher
 * — the mirror-image bug `PlatformReferenceAccessTest` exists to catch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // NOT NULL (FR-011أ): a year with no stage cannot answer "which broad
            // stage is this student in", which is the ONE thing every existing
            // reader of `grade_level_slug` asks. `restrictOnDelete` because a
            // stage with years under it is a stage nothing may delete — and the
            // taxonomy policy refuses deletion outright anyway.
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete();
            $table->string('name_ar');
            // Unique platform-wide, exactly as `regions.slug`. It is a text join
            // key stored on `student_profiles`, so it is frozen once written.
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Retired, never deleted: a student profile points here by slug and a
            // deleted row is a student in no year at all.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};
