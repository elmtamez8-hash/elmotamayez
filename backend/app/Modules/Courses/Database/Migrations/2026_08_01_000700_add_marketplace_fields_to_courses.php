<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketplace display fields for courses.
 *
 * Publishing itself adds nothing (R9): `status` + `visibility` + IsPublishable
 * already answer "may this be listed". These three are what the public card
 * shows and cannot be derived from anything the table holds — a course type
 * (FR-052), a cover image and a struck-through original price (FR-053).
 *
 * Rating and enrolment count stay derived: enrolments are already a table, and
 * inventing a denormalised counter before there is a write path to keep it
 * honest is how counters start lying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('course_type')->default('recorded')->index();
            $table->string('cover_path')->nullable();
            $table->decimal('price_before_discount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['course_type', 'cover_path', 'price_before_discount']);
        });
    }
};
