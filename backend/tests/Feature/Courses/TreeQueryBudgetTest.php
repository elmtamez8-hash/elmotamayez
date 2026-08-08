<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * SC-015 — the authoring tree costs the same whether it holds twenty items or
 * two hundred.
 *
 * A Resource runs once per row, so a query inside one is an N+1 by construction
 * — the same defect `ClassSessionResource` shipped with in 005, where every
 * published session asked separately where its recording went. This is the
 * endpoint every write on the authoring page re-reads afterwards, so a per-row
 * query here is paid on every keystroke-sized action.
 *
 * The assertion compares two sizes rather than pinning an absolute number: an
 * extra query added by something unrelated should not fail the build, but a
 * count that grows with the tree must.
 */
function budgetTree(int $workspaceId, int $chapters, int $lessonsPerChapter): Course
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    // One real target of each kind, shared by every reference item below. Shared
    // rather than one per item on purpose: it proves the resolution is batched by
    // TYPE, not merely cached per id.
    $exam = Exam::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
    ]);

    for ($c = 0; $c < $chapters; $c++) {
        $chapter = Chapter::create([
            'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => "فصل {$c}", 'status' => ContentStatus::Published, 'order' => $c,
        ]);

        for ($l = 0; $l < $lessonsPerChapter; $l++) {
            // Not all articles.
            //
            // Every lesson used to be `article`, so `ReferenceIntegrity::missingAmong`
            // returned early with zero queries and the exam / live_session branch of
            // `CourseTreeResource::lesson()` — the one that computes
            // `reference_missing` — was never exercised under the budget at all. A
            // per-row lookup added for a reference item would have passed this test.
            //
            // The exam and session rows carry real `reference_id`s so the branch is
            // taken with rows to resolve rather than skipped on nulls.
            $type = match ($l % 3) {
                0 => 'article',
                1 => 'exam',
                default => 'live_session',
            };

            Lesson::create([
                'workspace_id' => $workspaceId, 'course_id' => $course->id,
                'section_id' => $section->id, 'chapter_id' => $chapter->id,
                'uuid' => Str::uuid(), 'title' => "درس {$c}-{$l}", 'type' => $type,
                'status' => ContentStatus::Published, 'content' => 'نصّ',
                'order' => $l, 'duration_seconds' => 60,
                'reference_id' => match ($type) {
                    'exam' => $exam->id,
                    'live_session' => $session->id,
                    default => null,
                },
            ]);
        }
    }

    return $course;
}

function countTreeQueries(object $test, Course $course): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $test->getJson("/api/v1/courses/{$course->uuid}/tree")->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('costs the same for a small tree and a ten-times larger one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);

    // Three lessons per chapter in the small tree, not two: the reference
    // resolution costs one query per TYPE PRESENT, so a small tree missing
    // `live_session` entirely would be one query cheaper for a reason that has
    // nothing to do with size, and the comparison would fail on a system that is
    // behaving correctly. Both trees now hold all three types.
    $small = budgetTree($workspace->id, 2, 3);
    $large = budgetTree($workspace->id, 10, 20);

    // Warm whatever caches the first request populates, so the comparison is
    // between two trees rather than between a cold and a warm request.
    countTreeQueries($this, $small);

    $smallCost = countTreeQueries($this, $small);
    $largeCost = countTreeQueries($this, $large);

    // 7 nodes against 210. A per-row query would make the second number an order
    // of magnitude larger; a fixed set of eager loads makes them equal.
    expect($largeCost)->toBe($smallCost);
});
