<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Spec 032 · SC-005 — THE MOST IMPORTANT TEST IN THIS FEATURE.
 *
 * ⛔ Its inverse is the worst defect this repository records: an item that
 * enters the progress denominator and can never be completed caps every
 * enrolled student below 100% for ever, so `CourseCompleted` never fires and
 * NO CERTIFICATE IS EVER ISSUED — silently, for the whole course, permanently.
 * Six roads already lead there and are named in `docs/README.md`; a new lesson
 * type is a seventh, and it is not worth creating for a saving in bandwidth.
 *
 * ⚠️ BITE-CHECK: flip `completable` to false for `embed` in `LessonTypeRegistry`
 * and re-run. The denominator assertion must go red. Flip `self_completable` to
 * false instead and the complete call must 422. If either stays green the test
 * is measuring the article beside it.
 */
it('an embedded lesson completes and the course certifies', function (): void {
    Event::fake([CourseCompleted::class]);

    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');

    $course = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

    $section = Section::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'فصل', 'status' => ContentStatus::Published,
    ]);

    $make = fn (string $type, array $extra, int $order): Lesson => Lesson::create(array_merge([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'section_id' => $section->id,
        'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(),
        'title' => "عنصر {$type}",
        'type' => $type,
        'status' => ContentStatus::Published,
        'order' => $order,
    ], $extra));

    $embed = $make(LessonType::Embed->value, [
        'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'is_preview' => true,
    ], 0);

    $article = $make(LessonType::Article->value, ['content' => 'النصّ'], 1);

    // Two items in the tree and BOTH are work — the embed is not a free rider
    // in the denominator, and it is not missing from it either.
    expect(Lesson::query()->where('course_id', $course->id)->countableForProgress()->count())->toBe(2);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    foreach ([$embed, $article] as $lesson) {
        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson->uuid}/complete")
            ->assertOk();
    }

    expect((int) $enrollment->refresh()->progress_pct)->toBe(100);

    Event::assertDispatched(CourseCompleted::class);
});

/**
 * The event is faked above so the assertion is about the TRIGGER; here the
 * listener actually runs, because «the certificate issues» is the half a faked
 * event cannot prove.
 */
it('issues the certificate once the embedded lesson is finished', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');

    $course = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

    $section = Section::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'فصل', 'status' => ContentStatus::Published,
    ]);

    $embed = Lesson::create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'section_id' => $section->id,
        'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(),
        'title' => 'الحصّة التعريفيّة',
        'type' => LessonType::Embed->value,
        'status' => ContentStatus::Published,
        'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'is_preview' => true,
        'order' => 0,
    ]);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$embed->uuid}/complete")
        ->assertOk();

    expect((int) $enrollment->refresh()->progress_pct)->toBe(100)
        ->and(Certificate::query()->where('enrollment_id', $enrollment->getKey())->exists())->toBeTrue();
});
