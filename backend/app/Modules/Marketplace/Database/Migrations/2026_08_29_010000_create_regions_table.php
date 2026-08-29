<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T118 — where a student lives, as platform vocabulary (FR-042).
 *
 * ⚠️ NO `workspace_id`, UNLIKE ITS TWO NEIGHBOURS. `subjects` and `grade_levels`
 * still carry the column from before spec 009 promoted them; a region never did.
 * One person lives in one place whoever teaches them, and a copy per workspace
 * would make «توزيع الطلاب بالمنطقة» a count of teacher-region pairs rather than
 * of students — constitution v1.2.0 §I, platform reference (ب): read publicly,
 * written with a platform permission.
 *
 * `taxonomy.manage` is that permission and this is its second reader. Nothing
 * new was declared: a region is the same kind of decision as a subject, taken by
 * the same person, and a permission per catalogue is how a matrix stops being
 * readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name_ar');
            // Unique platform-wide, which is the whole point of the table having
            // no workspace column: `regions.slug` is what a report groups by.
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Retired, never deleted — `student_profiles.region_id` points here
            // and a deleted row is a student living nowhere. Same rule the
            // taxonomy policy already spells out for a slug.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
