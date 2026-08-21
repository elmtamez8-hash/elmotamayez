<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ExportArchive;

/**
 * SC-014 — fifty thousand rows, in the background, without exhausting memory.
 *
 * ⚠️ MEMORY IS WHAT FAILS HERE, NOT TIME AND NOT QUERY COUNT. The obvious
 * implementation — each module returning an array, the Action encoding thirteen of
 * them — peaks at roughly twice the serialised size of the largest, and
 * `supervisor-compliance` runs with a memory ceiling and `tries: 1`. An
 * out-of-memory kill is not retried: it leaves the request `processing` until the
 * stalled sweep finds it, and then does exactly the same thing again.
 *
 * ⚠️ SO THE ASSERTION IS ON THE PEAK, AND IT IS TAKEN AS A DELTA. `memory_get_peak_usage`
 * is process-wide and the suite has already allocated by the time this file runs, so
 * an absolute ceiling would measure the rest of the test run rather than the export.
 * The delta measures what THIS walk added.
 *
 * ⚠️ AND `lesson_progress` IS THE TABLE, BECAUSE IT IS THE ONE WITH NO USER COLUMN.
 * It reaches its student only through `enrollment_id`, so it is the walk that has a
 * parent-id list in front of it as well as pages underneath — the shape most likely
 * to be written as "load the ids, then load everything".
 */
const SCALE_ROWS = 50_000;

beforeEach(function (): void {
    Storage::fake('local');

    [$workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
        $now = now()->toDateTimeString();

        /*
        | ⚠️ FIFTY ENROLMENTS × A THOUSAND LESSONS, NOT ONE × FIFTY THOUSAND.
        | `lesson_progress` carries `unique(enrollment_id, lesson_id)` — one row per
        | lesson per enrolment — so the naive fixture violates a constraint before
        | the first page. The shape it forces is the better test anyway: the walk
        | now has a fifty-entry parent-id list in FRONT of it as well as pages
        | underneath, which is the two-level structure most likely to be written as
        | "load the ids, then load everything".
        */
        $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
        $section = Section::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
        ]);
        $chapter = Chapter::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'section_id' => $section->getKey(),
        ]);

        foreach (array_chunk(range(1, 1_000), 500) as $batch) {
            $rows = array_map(fn (int $i): array => [
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'section_id' => $section->getKey(),
                'chapter_id' => $chapter->getKey(),
                'uuid' => (string) Str::uuid(),
                'title' => 'درس '.$i,
                'type' => 'article',
                'order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ], $batch);

            DB::table('lessons')->insert($rows);
        }

        $lessonIds = DB::table('lessons')->where('course_id', $course->getKey())->pluck('id')->all();

        $enrollmentIds = [];

        foreach (range(1, 50) as $i) {
            $enrollmentIds[] = Enrollment::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => Course::factory()->create(['workspace_id' => $workspace->getKey()])->getKey(),
                'student_user_id' => $this->student->getKey(),
                'status' => 'active',
            ])->getKey();
        }

        // Raw bulk inserts: fifty thousand model saves would make the FIXTURE the
        // slow part and prove nothing about the code under test, and
        // `lesson_progress` has no uuid to be booted for.
        foreach ($enrollmentIds as $enrollmentId) {
            foreach (array_chunk($lessonIds, 500) as $batch) {
                DB::table('lesson_progress')->insert(array_map(fn (int $lessonId): array => [
                    'workspace_id' => $workspace->getKey(),
                    'enrollment_id' => $enrollmentId,
                    'lesson_id' => $lessonId,
                    'status' => 'completed',
                    'time_spent_seconds' => 60,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch));
            }
        }
    });
});

it('exports fifty thousand rows without holding them', function (): void {
    $request = app(CreateDataRequest::class)->handle(
        $this->student,
        (string) $this->student->uuid,
        DataRequestType::Export,
    );

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $added = memory_get_peak_usage(true) - $before;

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and(ExportArchive::rows($request, 'lesson_progress'))->toHaveCount(SCALE_ROWS)
        /*
        | 64 MiB. Not a number tuned until it passed: the pages are 500 rows of a
        | handful of scalars, so the walk's own working set is well under a
        | megabyte, and anything approaching this ceiling means a full array is
        | being held somewhere — which is the defect, whatever the exact size.
        */
        ->and($added)->toBeLessThan(64 * 1024 * 1024);
})->group('slow');
