<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

/*
| Spec 023 · T011 — the course page costs the same whatever the course holds.
|
| ⚠️ FLATNESS ALONE IS NOT THE TEST, AND MEASURING IT ALONE INVERTS THE RESULT.
| `PublicCourseDetailResource` reads the subject, the author's profile and the
| grouped tree. Drop an eager load and the payload's keys go MISSING rather than
| expensive — a `?->` chain answers null, the page is one query CHEAPER, and a
| budget test reads the regression as an improvement. Six call sites shipped a
| blank teacher name that way in 010, and none of them cost a query.
|
| So the two assertions guard the two opposite mistakes: the count must not grow
| with the tree, AND every derived field must still be there.
*/

/** @return array{0: Course, 1: TeacherProfile} */
function budgetCourse(int $sections, int $lessonsPerChapter): array
{
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher, $sections, $lessonsPerChapter): Course {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
        ]);

        foreach (range(1, $sections) as $s) {
            $section = Section::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'title' => "قسم {$s}", 'status' => ContentStatus::Published, 'order' => $s,
            ]);

            foreach (range(1, 2) as $c) {
                $chapter = Chapter::create([
                    'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
                    'course_id' => $course->getKey(), 'title' => "فصل {$s}-{$c}",
                    'status' => ContentStatus::Published, 'order' => $c,
                ]);

                foreach (range(1, $lessonsPerChapter) as $l) {
                    Lesson::create([
                        'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                        'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                        'uuid' => Str::uuid(), 'title' => "درس {$s}-{$c}-{$l}", 'type' => 'article',
                        'status' => ContentStatus::Published, 'order' => $l, 'duration_seconds' => 300,
                    ]);
                }
            }
        }

        return $course;
    });

    return [$course, $teacher];
}

it('serves the course page at a cost that does not grow with the tree', function (): void {
    [$small] = budgetCourse(sections: 1, lessonsPerChapter: 1);
    [$large] = budgetCourse(sections: 4, lessonsPerChapter: 6);

    $this->asGuest();

    // Warm-up: the first request in the process pays for boot-time reads that
    // belong to neither measurement.
    $this->getJson("/api/v1/marketplace/courses/{$small->uuid}")->assertOk();

    [$cheap] = countingQueries(
        fn () => $this->getJson("/api/v1/marketplace/courses/{$small->uuid}")->assertOk(),
    );

    [$dear, $response] = countingQueries(
        fn () => $this->getJson("/api/v1/marketplace/courses/{$large->uuid}")->assertOk(),
    );

    // 2 lessons vs 48, and the same handful of queries.
    expect($dear)->toBeLessThanOrEqual($cheap);

    $data = $response->json('data');

    /*
    | The other half. Each of these comes from a relation the Action eager-loads;
    | drop one and the key is null, the page is cheaper, and the assertion above
    | passes more comfortably than before.
    */
    expect($data['teacher'])->not->toBeNull()
        ->and(trim((string) $data['teacher']['name']))->not->toBe('')
        ->and($data['subject'])->not->toBeNull()
        ->and($data['subject']['name_ar'])->toBeString()
        ->and($data['curriculum'])->toHaveCount(4)
        ->and($data['curriculum'][0]['chapters'])->toHaveCount(2)
        ->and($data['curriculum'][0]['chapters'][0]['items'])->toHaveCount(6)
        ->and($data['lessons_count'])->toBe(48);
});
