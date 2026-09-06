<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The teacher's name and the subject become printed facts, so they are frozen at
| issue exactly as `student_display_name` already is (spec 021's migration says
| why). Reading them live would mean the certificate says something different
| after a rename, a subject re-filing, or an erasure — and answers with nobody at
| all once `courses.created_by` points at a deleted account.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->string('teacher_display_name')->nullable()->after('student_display_name');
            $table->string('subject_display_name')->nullable()->after('teacher_display_name');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn(['teacher_display_name', 'subject_display_name']);
        });
    }

    /*
    | ⚠️ QUERY BUILDER, NOT THE MODEL. `Certificate` carries `BelongsToWorkspace`
    | and a migration runs with no workspace context at all, so an Eloquent walk
    | here reads whatever `users.last_workspace_id` happens to resolve to — which
    | on a fresh deploy is nothing, and on a live one is one teacher's rows.
    |
    | ⚠️ `chunkById`, NEVER `chunk`. `chunk` paginates by OFFSET, so any walk whose
    | result set can move under it skips as many rows as the previous page changed
    | AND REPORTS SUCCESS. `chunkById` keys on `id > last`, which cannot skip.
    |
    | ⚠️ AND `users` HAS NO `name` COLUMN — it is an accessor over `first_name` and
    | `last_name`. A constrained select naming `name` is either an SQL error or, on
    | the shape this repository has already shipped six times, a silently blank
    | name on every screen that prints it.
    */
    private function backfill(): void
    {
        DB::table('certificates')
            ->select('id', 'course_id')
            ->orderBy('id')
            ->chunkById(500, function ($certificates): void {
                $courseIds = $certificates->pluck('course_id')->unique()->all();

                $courses = DB::table('courses')
                    ->leftJoin('users', 'users.id', '=', 'courses.created_by')
                    ->leftJoin('subjects', 'subjects.id', '=', 'courses.subject_id')
                    ->whereIn('courses.id', $courseIds)
                    ->get([
                        'courses.id',
                        'courses.title',
                        'users.first_name',
                        'users.last_name',
                        'subjects.name_ar',
                    ])
                    ->keyBy('id');

                foreach ($certificates as $certificate) {
                    $course = $courses->get($certificate->course_id);

                    if ($course === null) {
                        continue;
                    }

                    $teacher = trim(($course->first_name ?? '').' '.($course->last_name ?? ''));

                    DB::table('certificates')
                        ->where('id', $certificate->id)
                        ->update([
                            'teacher_display_name' => $teacher !== '' ? $teacher : null,
                            'subject_display_name' => $course->name_ar ?? $course->title,
                        ]);
                }
            });
    }
};
