<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;

/*
| A node built by a factory must be reachable by the walk that renders it.
|
| ⚠️ THIS IS NOT A TEST OF THE FACTORIES' TIDINESS. `ChapterFactory` and
| `LessonFactory` each named an independent `Course::factory()` — so a bare
| `Chapter::factory()` produced a chapter whose `course_id` was a course its own
| section had never heard of, and a bare `Lesson::factory()` produced four
| unrelated courses in one call. The course tree walks `section_id`/`chapter_id`
| and shows such a node; `StoreLessonRequest` asks
| `exists('course_chapters','uuid')->where('course_id', $course)` and refuses
| every item added to it — «القيمة المختارة في chapter uuid غير موجودة», on a
| chapter sitting there on the screen. Eight of them reached the development
| database, and the teacher who hit one reported it as a product defect.
|
| ⚠️ AND NO EXISTING TEST COULD SEE IT: every fixture in the suite pins the
| parents by hand, which is what a factory whose defaults disagree with each
| other teaches everybody to do.
*/
it('builds a chapter whose course is its section\'s course', function (): void {
    $chapter = Chapter::factory()->create();

    expect($chapter->course_id)->toBe($chapter->section->course_id);
});

it('builds a lesson whose whole chain agrees', function (): void {
    $lesson = Lesson::factory()->create();

    expect($lesson->chapter_id)->not->toBeNull()
        ->and($lesson->section_id)->toBe($lesson->chapter->section_id)
        ->and($lesson->course_id)->toBe($lesson->chapter->course_id)
        ->and($lesson->course_id)->toBe($lesson->chapter->section->course_id);
});

it('keeps the chain when a parent is pinned by hand', function (): void {
    // The ordinary shape in this suite: the caller names the section, and the
    // derivation must follow it rather than the factory's own default.
    $chapter = Chapter::factory()->create();
    $lesson = Lesson::factory()->create(['chapter_id' => $chapter->getKey()]);

    expect($lesson->section_id)->toBe($chapter->section_id)
        ->and($lesson->course_id)->toBe($chapter->course_id);
});
