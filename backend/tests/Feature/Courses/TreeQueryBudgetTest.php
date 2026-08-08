<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
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

    for ($c = 0; $c < $chapters; $c++) {
        $chapter = Chapter::create([
            'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => "فصل {$c}", 'status' => ContentStatus::Published, 'order' => $c,
        ]);

        for ($l = 0; $l < $lessonsPerChapter; $l++) {
            Lesson::create([
                'workspace_id' => $workspaceId, 'course_id' => $course->id,
                'section_id' => $section->id, 'chapter_id' => $chapter->id,
                'uuid' => Str::uuid(), 'title' => "درس {$c}-{$l}", 'type' => 'article',
                'status' => ContentStatus::Published, 'content' => 'نصّ',
                'order' => $l, 'duration_seconds' => 60,
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

    $small = budgetTree($workspace->id, 2, 2);
    $large = budgetTree($workspace->id, 10, 20);

    // Warm whatever caches the first request populates, so the comparison is
    // between two trees rather than between a cold and a warm request.
    countTreeQueries($this, $small);

    $smallCost = countTreeQueries($this, $small);
    $largeCost = countTreeQueries($this, $large);

    // 5 items against 210. A per-row query would make the second number an order
    // of magnitude larger; a fixed set of eager loads makes them equal.
    expect($largeCost)->toBe($smallCost);
});
