<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Actions\PreviewPublishImpact;
use App\Modules\Courses\Actions\PublishTreeNodes;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The preview tells the truth, and publishing makes it come true (`SC-018`).
 *
 * Every case here follows the same shape: read the preview, publish exactly the
 * items it returned, then compare the STORED percentages to what was shown. That
 * comparison is the whole point — a preview is worth nothing if it is a second
 * estimate rather than the same computation asked early, and the only way to
 * know which it is, is to run both.
 *
 * It also pins the thing that made the comparison possible at all: publishing
 * used to leave `progress_pct` untouched, because it is written when a LESSON is
 * completed and at no other moment. So the drop the teacher was promised existed
 * on the preview screen and nowhere else (`FR-051`), and — worse — archiving the
 * last item a student had left took their remaining work to zero with nothing
 * left to complete, so `CourseCompleted` could never fire for them again.
 */

/**
 * A running course: one published section and chapter, four published articles,
 * two drafts, and a student who has finished two of the four.
 *
 * @return array{0: Course, 1: Enrollment, 2: Section, 3: Chapter, 4: array<string, Lesson>, 5: int}
 */
function populatedCourse(bool $sequential = false): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();
    $student = test()->addWorkspaceMember($workspace, 'student');

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $sequential): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'is_sequential' => $sequential,
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $make = function (string $key, int $order, ContentStatus $status, array $extra = []) use ($workspace, $course, $section, $chapter): Lesson {
            return Lesson::create([
                'workspace_id' => $workspace->id, 'course_id' => $course->id,
                'section_id' => $section->id, 'chapter_id' => $chapter->id,
                'uuid' => Str::uuid(), 'title' => $key, 'type' => 'article',
                'content' => 'نصّ', 'status' => $status, 'order' => $order,
                ...$extra,
            ]);
        };

        $lessons = [
            'one' => $make('الأول', 1, ContentStatus::Published),
            'two' => $make('الثاني', 2, ContentStatus::Published),
            // Slotted BETWEEN two published items, not appended after them. A
            // draft at the end of a tree stands in front of nothing, so a
            // sequential test built that way passes with the ordering broken.
            'draftA' => $make('مسودّة أ', 3, ContentStatus::Draft),
            'three' => $make('الثالث', 4, ContentStatus::Published),
            'four' => $make('الرابع', 5, ContentStatus::Published),
            'draftB' => $make('مسودّة ب', 6, ContentStatus::Draft),
        ];

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 50,
            'enrolled_at' => now(),
        ]);

        foreach (['one', 'two'] as $done) {
            $enrollment->progress()->create([
                'workspace_id' => $workspace->id,
                'lesson_id' => $lessons[$done]->id,
                'status' => 'completed',
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }

        return [$course, $enrollment, $section, $chapter, $lessons, (int) $student->id];
    });
}

/** @param  list<array{uuid: string, status: string}>|null  $items */
function previewOf(Course $course, ?array $items = null): array
{
    return app(PreviewPublishImpact::class)->handle($course, $items);
}

it('promises a drop that the publish then delivers, to the point', function (): void {
    [$course, $enrollment] = populatedCourse();

    $preview = previewOf($course);

    // Four countable items become six; the student's two completions go from
    // 50% to 33%. That is the number the teacher is shown.
    expect($preview['added_items'])->toBe(2)
        ->and($preview['removed_items'])->toBe(0)
        ->and($preview['students_affected'])->toBe(1)
        ->and($preview['largest_drop_pct'])->toBe(-17);

    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    // And the number the student's screen carries afterwards. Before the resync
    // listener existed this assertion read 50 — the drop was visible to the
    // teacher alone, which is not what FR-051 says.
    expect((int) $enrollment->refresh()->progress_pct)->toBe(33);
});

it('counts the already-published items a chapter-only publish reveals', function (): void {
    [$course, $enrollment, $section, $chapter, $lessons] = populatedCourse();

    // The tree the other way round: everything published except the chapter that
    // holds it. Nothing is visible, so nothing counts.
    $chapter->forceFill(['status' => ContentStatus::Draft])->save();
    $lessons['draftA']->forceFill(['status' => ContentStatus::Published])->save();
    $lessons['draftB']->forceFill(['status' => ContentStatus::Published])->save();

    // The batch names ONE node and no lesson at all. A preview that only looked
    // at the items it was handed would answer "nothing added" — and six items
    // would land in every student's denominator anyway.
    $preview = previewOf($course, [['uuid' => $chapter->uuid, 'status' => 'published']]);

    expect($preview['added_items'])->toBe(6);

    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    expect((int) $enrollment->refresh()->progress_pct)->toBe(33);
    expect($section->fresh()?->status)->toBe(ContentStatus::Published);
});

it('does not count a lesson published inside a chapter that stays a draft', function (): void {
    [$course, , , $chapter, $lessons] = populatedCourse();

    $chapter->forceFill(['status' => ContentStatus::Draft])->save();

    // Publishing the item alone changes nothing anyone can see: its chapter is
    // still closed. Counting it would be the fourth road into the forever-bug —
    // an item in the denominator that no student can ever open.
    $preview = previewOf($course, [['uuid' => $lessons['draftA']->uuid, 'status' => 'published']]);

    expect($preview['added_items'])->toBe(0)
        ->and($preview['students_affected'])->toBe(0);
});

it('never counts a recording, a note or an item whose exam is gone', function (): void {
    [$course, $enrollment, $section, $chapter, $lessons] = populatedCourse();

    $workspace = $course->workspace_id;

    $extra = function (string $title, string $type, int $order, array $more) use ($workspace, $course, $section, $chapter): Lesson {
        return Lesson::create([
            'workspace_id' => $workspace, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => $title, 'type' => $type,
            'status' => ContentStatus::Published, 'order' => $order, ...$more,
        ]);
    };

    // Three items that are already live and none of which may ever enter a
    // denominator: a recording answers to a SEAT, a note asks nothing, and an
    // exam item pointing at a deleted exam can be completed by nobody at all.
    // Published directly rather than through the batch, because two of them
    // could not pass PublishReadiness — which is itself the point: they are in
    // the tree, they are visible, and they still must not count.
    $extra('تسجيل الحصة', 'video', 7, ['class_session_id' => 4242]);
    $extra('تنويه', 'note', 8, ['content' => 'اقرأ']);
    $extra('اختبار محذوف', 'exam', 9, ['reference_id' => 999_999]);

    $preview = previewOf($course);

    // Only the two article drafts.
    expect($preview['added_items'])->toBe(2);

    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    expect((int) $enrollment->refresh()->progress_pct)->toBe(33);
});

it('predicts the credit an exam item gives to whoever already sat it', function (): void {
    [$course, $enrollment, $section, $chapter, , $studentId] = populatedCourse();

    $exam = Exam::factory()->published()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->id,
        'passing_score' => 60,
    ]);

    $item = Lesson::create([
        'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'اختبار الوحدة', 'type' => 'exam',
        'status' => ContentStatus::Draft, 'order' => 7, 'reference_id' => $exam->id,
    ]);

    // The ordinary order of events, not an edge case: the exam is answered from
    // its own page long before the teacher decides where it belongs.
    Attempt::create([
        'workspace_id' => $course->workspace_id,
        'exam_id' => $exam->id,
        'enrollment_id' => $enrollment->id,
        'student_user_id' => $studentId,
        'status' => 'graded',
        'score' => 80, 'max_score' => 100, 'passed' => true,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    $preview = previewOf($course, [['uuid' => $item->uuid, 'status' => 'published']]);

    // Five countable items, three of them done — because publishing this item
    // ALSO credits the attempt. A preview reading lesson_progress as it stands
    // would have promised 2/5 = 40% and delivered 60%.
    expect($preview['added_items'])->toBe(1)
        ->and($preview['largest_gain_pct'])->toBe(10);

    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    expect((int) $enrollment->refresh()->progress_pct)->toBe(60);
});

it('lowers a finished student\'s percentage without taking their completion back', function (): void {
    [$course, $enrollment, , , $lessons] = populatedCourse();

    // Finish the course properly first.
    foreach (['three', 'four'] as $done) {
        $enrollment->progress()->create([
            'workspace_id' => $course->workspace_id,
            'lesson_id' => $lessons[$done]->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    $enrollment->update(['status' => 'completed', 'progress_pct' => 100, 'completed_at' => now()]);

    $preview = previewOf($course);
    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    // FR-050 and FR-051 together: content added after someone finished lowers
    // the number and leaves the fact alone. Their certificate has already issued
    // and nothing here reaches for it.
    expect((int) $enrollment->refresh()->progress_pct)->toBe(67)
        ->and($enrollment->status)->toBe('completed');
});

it('completes a student when archiving takes away the only item they had left', function (): void {
    [$course, $enrollment, , , $lessons] = populatedCourse();

    $enrollment->progress()->create([
        'workspace_id' => $course->workspace_id,
        'lesson_id' => $lessons['three']->id,
        'status' => 'completed',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    // Three of four done. The teacher retires the fourth — so the student's
    // remaining work is now zero, and there is no lesson left for them to
    // complete. Completion is otherwise only ever decided when one IS completed,
    // so without the resync this enrolment sat at 100% with no CourseCompleted
    // and no certificate, permanently. The fifth road into the same bug.
    $preview = previewOf($course, [['uuid' => $lessons['four']->uuid, 'status' => 'archived']]);

    expect($preview['removed_items'])->toBe(1)
        ->and($preview['largest_gain_pct'])->toBe(25);

    app(PublishTreeNodes::class)->handle($course, $preview['items'], $preview['structure_version']);

    expect((int) $enrollment->refresh()->progress_pct)->toBe(100)
        ->and($enrollment->status)->toBe('completed');
});

it('names the item that will start standing in front of another', function (): void {
    [$course, , , , $lessons] = populatedCourse(sequential: true);

    // The fixture already sits the draft between two published items. In a
    // sequential course publishing it is not a display change: it becomes the
    // thing the student must finish before the item after it opens.
    $preview = previewOf($course, [['uuid' => $lessons['draftA']->uuid, 'status' => 'published']]);

    expect($preview['resequenced'])->toHaveCount(1)
        ->and($preview['resequenced'][0]['title'])->toBe('الثالث')
        ->and($preview['resequenced'][0]['unlocked_by'])->toBe('مسودّة أ');
});

it('says nothing about unlock order in a course that is not sequential', function (): void {
    [$course, , , , $lessons] = populatedCourse();

    expect(previewOf($course, [['uuid' => $lessons['draftA']->uuid, 'status' => 'published']])['resequenced'])
        ->toBe([]);
});

it('warns before a recording is hidden and before a course is emptied', function (): void {
    [$course, , $section, $chapter, $lessons] = populatedCourse();

    $recording = Lesson::create([
        'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'حصة الأحد', 'type' => 'video',
        'status' => ContentStatus::Published, 'order' => 7, 'class_session_id' => 77,
    ]);

    $hide = previewOf($course, [['uuid' => $recording->uuid, 'status' => 'archived']]);

    // FR-053: it is allowed, and it is the only way anyone who was in that room
    // reaches the recording. Refusing would be wrong; saying nothing is worse.
    expect(collect($hide['warnings'])->pluck('code')->all())->toBe(['recording_hidden']);

    // FR-055: pulling the section back to draft leaves students a course they
    // open and find empty. Also allowed, also worth saying out loud.
    $empty = previewOf($course, [['uuid' => $section->uuid, 'status' => 'draft']]);

    expect(collect($empty['warnings'])->pluck('code')->all())->toBe(['course_emptied']);
    expect($lessons)->not->toBeEmpty();
});

it('refuses the preview of a batch the publish itself would refuse', function (): void {
    [$course, , , , $lessons] = populatedCourse();

    // An article with nothing written in it. The publish refuses this (FR-022),
    // so a preview that costed it and answered "1 item, 1 student" would be
    // inviting the teacher to press a button that 422s.
    $lessons['draftA']->forceFill(['content' => ''])->save();

    expect(fn () => previewOf($course))->toThrow(DomainException::class);
});
