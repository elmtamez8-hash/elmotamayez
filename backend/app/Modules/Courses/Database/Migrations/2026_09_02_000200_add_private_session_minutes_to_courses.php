<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 023 · T024 — how long a private session in this course lasts (FR-016أ).
|
| The teacher declares it once per course and the student READS it; they do not
| choose. That is what keeps «حصّة» one unit with one meaning — what a credit
| buys, what the teacher is paid for, and what is taken out of their calendar.
| A duration the student picked would make all three of those vary per booking.
|
| NULL means «the platform default», not «no private sessions»: a course whose
| teacher never opened this field still answers a request form with sixty
| minutes, rather than one that cannot be submitted.
|
| ⚠️ AND IT GOES INTO `$fillable` IN THE SAME CHANGE. A column a migration adds
| and mass assignment does not know about is a column that is NEVER WRITTEN, in
| silence — no exception, no log, a 200, and a form that appears to work. Spec
| 013 shipped three of them on `student_profiles` at once and every assertion
| over them was true, because they were made against the response body, which
| echoes what was submitted rather than what was stored.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedSmallInteger('private_session_minutes')->nullable()->after('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('private_session_minutes');
        });
    }
};
