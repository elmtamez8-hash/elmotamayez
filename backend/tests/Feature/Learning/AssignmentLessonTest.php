<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\SaveAssignment;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Courses\Actions\PublishTreeNodes;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\ReferenceIntegrity;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| An assignment placed in a course's curriculum, end to end.
|
| ⛔ THE DECISION UNDER TEST: the item is completed by the HAND-IN
| (`CompleteAssignmentLessonOnSubmission`), never by a button — and so every
| case below is also a case about the progress denominator. An assignment item
| that enters the denominator and cannot be completed caps every enrolled
| student below 100% for ever: no `CourseCompleted`, no certificate. The
| «reaches 100%» cases are the ones that matter, in BOTH directions — a hand-in
| finishes the course, and a homework that goes away (unpublished, deleted,
| moved) leaves the course finishable without it.
|
| Titles in the exposure cases are ASCII: `getContent()` escapes Arabic, so an
| Arabic needle is vacuously absent.
*/

/**
 * A published course: one article, one assignment item over a published
 * homework, and an active enrolment for a SELF-REGISTERED student — no
 * `last_workspace_id`, which is the student production actually has.
 *
 * @return array{workspace: Workspace, owner: User, student: User, course: Course, chapter: Chapter, article: Lesson, item: Lesson, assignment: Assignment, enrollment: Enrollment}
 */
function assignmentLessonCourse(array $assignmentAttributes = [], ContentStatus $itemStatus = ContentStatus::Published): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $assignmentAttributes, $itemStatus): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            // Free order, so the item is open without the article first — the
            // sequence is `LessonGate`'s question, not this file's.
            'is_sequential' => false,
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(),
            'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $assignment = Assignment::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'title' => 'HOMEWORK-PLACED',
            'points' => 20,
            'due_at' => now()->addDays(3),
            ...$assignmentAttributes,
        ]);

        $article = Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => 'المقالة', 'type' => 'article',
            'status' => ContentStatus::Published, 'order' => 1, 'content' => 'نصّ',
        ]);

        $item = Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => 'واجب الوحدة', 'type' => 'assignment',
            'reference_id' => $assignment->getKey(),
            'status' => $itemStatus, 'order' => 2,
        ]);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        return compact('course', 'chapter', 'article', 'item', 'assignment', 'enrollment');
    });

    return ['workspace' => $workspace, 'owner' => $owner, 'student' => $student, ...$built];
}

/** Signs the student in with the context production gives them: unresolved. */
function actAsAssignmentStudent(User $student): void
{
    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);
}

function assignmentItemCompleted(Enrollment $enrollment, Lesson $item): bool
{
    // Unscoped: the reader may be signed in as a stamped student whose context
    // is another workspace, and the question is about the row, not the reader.
    return LessonProgress::query()
        ->withoutWorkspaceScope()
        ->where('enrollment_id', $enrollment->getKey())
        ->where('lesson_id', $item->getKey())
        ->where('status', 'completed')
        ->exists();
}

/*
|--------------------------------------------------------------------------
| The teacher's side: the picker list and the write
|--------------------------------------------------------------------------
*/

it('lists only THIS course\'s published homework as a target', function (): void {
    $fx = assignmentLessonCourse();

    [$foreignWorkspace, $foreignOwner] = $this->createWorkspaceWithOwner();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        Assignment::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => $fx['course']->getKey(),
            'created_by' => $fx['owner']->getKey(), 'title' => 'DRAFT-HW',
        ]);

        $otherCourse = Course::factory()->create(['workspace_id' => $fx['workspace']->getKey()]);
        Assignment::factory()->published()->create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => $otherCourse->getKey(),
            'created_by' => $fx['owner']->getKey(), 'title' => 'OTHER-COURSE-HW',
        ]);

        Assignment::factory()->published()->create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => null,
            'created_by' => $fx['owner']->getKey(), 'title' => 'COURSELESS-HW',
        ]);
    });

    app(WorkspaceContext::class)->forWorkspace($foreignWorkspace, function () use ($foreignWorkspace, $foreignOwner, $fx): void {
        // Another teacher's homework, pointed at our course's id by accident of
        // data — the workspace scope is what keeps it out.
        Assignment::factory()->published()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'course_id' => $fx['course']->getKey(),
            'created_by' => $foreignOwner->getKey(), 'title' => 'FOREIGN-HW',
        ]);
    });

    Sanctum::actingAs($fx['owner']);
    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);

    $titles = collect($this->getJson("/api/v1/courses/{$fx['course']->uuid}/reference-targets")
        ->assertOk()
        ->json('assignments'))->pluck('title')->all();

    expect($titles)->toBe(['HOMEWORK-PLACED']);
});

it('places a published homework of this course, and refuses a foreign, another course\'s or a draft one', function (): void {
    $fx = assignmentLessonCourse();

    [$foreignWorkspace, $foreignOwner] = $this->createWorkspaceWithOwner();
    $foreign = app(WorkspaceContext::class)->forWorkspace($foreignWorkspace, fn () => Assignment::factory()->published()->create([
        'workspace_id' => $foreignWorkspace->getKey(),
        'course_id' => Course::factory()->create(['workspace_id' => $foreignWorkspace->getKey()])->getKey(),
        'created_by' => $foreignOwner->getKey(),
    ]));

    [$otherCourseHw, $draft] = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], fn () => [
        Assignment::factory()->published()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => Course::factory()->create(['workspace_id' => $fx['workspace']->getKey()])->getKey(),
            'created_by' => $fx['owner']->getKey(),
        ]),
        Assignment::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => $fx['course']->getKey(),
            'created_by' => $fx['owner']->getKey(),
        ]),
    ]);

    Sanctum::actingAs($fx['owner']);
    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);

    $post = fn (string $uuid) => $this->postJson("/api/v1/courses/{$fx['course']->uuid}/lessons", [
        'chapter_uuid' => $fx['chapter']->uuid,
        'title' => 'واجب',
        'type' => 'assignment',
        'reference_uuid' => $uuid,
    ]);

    $before = Lesson::query()->withoutWorkspaceScope()->where('course_id', $fx['course']->getKey())->count();

    $post($foreign->uuid)->assertStatus(422);
    $post($otherCourseHw->uuid)->assertStatus(422);
    $post($draft->uuid)->assertStatus(422);

    expect(Lesson::query()->withoutWorkspaceScope()->where('course_id', $fx['course']->getKey())->count())->toBe($before);

    // The positive control: without it a build refusing every assignment item
    // passes the three refusals above.
    $post($fx['assignment']->uuid)
        ->assertCreated()
        ->assertJsonPath('reference.uuid', $fx['assignment']->uuid)
        ->assertJsonPath('reference.title', 'HOMEWORK-PLACED');
});

it('refuses to move a placed homework to another course', function (): void {
    $fx = assignmentLessonCourse();

    $other = Course::factory()->create(['workspace_id' => $fx['workspace']->getKey()]);

    expect(fn () => app(SaveAssignment::class)->handle(
        (int) $fx['workspace']->getKey(),
        $fx['owner'],
        ['title' => 'HOMEWORK-PLACED', 'course_id' => $other->getKey()],
        $fx['assignment'],
    ))->toThrow(DomainException::class, 'لا يُنقل');

    expect((int) $fx['assignment']->refresh()->course_id)->toBe((int) $fx['course']->getKey());
});

/*
|--------------------------------------------------------------------------
| The student's lesson page
|--------------------------------------------------------------------------
*/

it('shows the student what the item asks, and offers no self-complete button', function (bool $stamped): void {
    $fx = assignmentLessonCourse();

    if ($stamped) {
        // A student some OTHER teacher once added to their workspace — the
        // scope would AND that workspace onto every read. `forceFill` because
        // the column is guarded and `create([...])` drops it in silence.
        [$elsewhere] = $this->createWorkspaceWithOwner();
        $fx['student']->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    }

    actAsAssignmentStudent($fx['student']);

    $this->getJson("/api/v1/learn/lessons/{$fx['item']->uuid}")
        ->assertOk()
        ->assertJsonPath('can_access', true)
        ->assertJsonPath('lesson.type', 'assignment')
        ->assertJsonPath('lesson.is_completable', true)
        ->assertJsonPath('lesson.may_self_complete', false)
        ->assertJsonPath('lesson.reference.uuid', $fx['assignment']->uuid)
        ->assertJsonPath('lesson.reference.points', 20)
        ->assertJsonPath('lesson.reference.submission_type', 'text');

    // The door refuses what the screen hides.
    $this->postJson("/api/v1/enrollments/{$fx['enrollment']->uuid}/lessons/{$fx['item']->uuid}/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NOT_SELF_COMPLETABLE');

    // …and the homework itself is reachable from the same student, which is
    // where the embedded hand-in reads it from.
    $this->getJson("/api/v1/assignments/{$fx['assignment']->uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $fx['assignment']->uuid);
})->with([
    'self-registered (null context)' => false,
    'stamped with another workspace' => true,
]);

/*
|--------------------------------------------------------------------------
| Completion, and the denominator in both directions
|--------------------------------------------------------------------------
*/

it('finishes the course on the HAND-IN — the item is what reaches 100%', function (bool $stamped): void {
    Event::fake([CourseCompleted::class]);

    $fx = assignmentLessonCourse();

    if ($stamped) {
        // The hand-in runs the whole chain — listener, `MarkLessonComplete`,
        // the denominator read — inside THIS student's request, which is where
        // a scoped relation read answers an empty course for a stamped student.
        [$elsewhere] = $this->createWorkspaceWithOwner();
        $fx['student']->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    }

    app(MarkLessonComplete::class)->handle($fx['enrollment'], (int) $fx['article']->getKey());

    expect((float) $fx['enrollment']->refresh()->progress_pct)->toBe(50.0);
    Event::assertNotDispatched(CourseCompleted::class);

    actAsAssignmentStudent($fx['student']);

    $this->postJson("/api/v1/assignments/{$fx['assignment']->uuid}/submissions", ['answer_text' => 'إجابتي'])
        ->assertCreated();

    expect(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeTrue()
        ->and((float) $fx['enrollment']->refresh()->progress_pct)->toBe(100.0)
        ->and($fx['enrollment']->status)->toBe('completed');

    Event::assertDispatched(CourseCompleted::class);
})->with([
    'self-registered (null context)' => false,
    'stamped with another workspace' => true,
]);

it('completes the item once, however many times the work is handed in', function (): void {
    $fx = assignmentLessonCourse();
    $submit = app(SubmitAssignment::class);

    $submit->handle($fx['assignment'], $fx['student'], 'المسوّدة الأولى');
    $submit->handle($fx['assignment']->refresh(), $fx['student'], 'بعد التصحيح');

    expect(LessonProgress::query()
        ->where('enrollment_id', $fx['enrollment']->getKey())
        ->where('lesson_id', $fx['item']->getKey())
        ->count())->toBe(1)
        ->and(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeTrue();
});

it('completes the item on a late hand-in the homework accepts — lateness costs marks, not progress', function (): void {
    $fx = assignmentLessonCourse([
        'due_at' => now()->subDays(2),
        'late_policy' => Assignment::LATE_ACCEPT,
    ]);

    $submission = app(SubmitAssignment::class)->handle($fx['assignment'], $fx['student'], 'متأخّر');

    expect($submission->state)->toBe(Submission::STATE_LATE)
        ->and(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeTrue();
});

it('does not complete the item for a student whose late hand-in was refused', function (): void {
    $fx = assignmentLessonCourse([
        'due_at' => now()->subDays(2),
        'late_policy' => Assignment::LATE_REJECT,
    ]);

    expect(fn () => app(SubmitAssignment::class)->handle($fx['assignment'], $fx['student'], 'متأخّر جداً'))
        ->toThrow(DomainException::class);

    expect(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeFalse();
});

it('lets the course reach 100% when the homework goes away', function (string $how): void {
    Event::fake([CourseCompleted::class]);

    $fx = assignmentLessonCourse();

    /*
    | Three roads to an item nothing can complete: the homework pulled back to
    | draft (`SubmitAssignment` refuses it), deleted outright, or moved to a
    | course whose students cannot see it. Each must drop the item OUT of the
    | denominator — otherwise the article below finishes a course at 50%.
    | Written with `DB::table` because the point is the state, whatever door
    | produced it.
    */
    match ($how) {
        'unpublished' => DB::table('assignments')->where('id', $fx['assignment']->getKey())->update(['status' => Assignment::STATUS_DRAFT]),
        'deleted' => DB::table('assignments')->where('id', $fx['assignment']->getKey())->delete(),
        'moved' => DB::table('assignments')->where('id', $fx['assignment']->getKey())->update([
            'course_id' => Course::factory()->create(['workspace_id' => $fx['workspace']->getKey()])->getKey(),
        ]),
    };

    expect(Lesson::query()->withoutWorkspaceScope()
        ->where('course_id', $fx['course']->getKey())
        ->countableForProgress()
        ->pluck('id')->all())->toBe([(int) $fx['article']->getKey()]);

    // And the teacher's tree marks it, so they know to repoint or remove it.
    expect(ReferenceIntegrity::missingAmong([$fx['item']->refresh()]))
        ->toHaveKey((int) $fx['item']->getKey());

    app(MarkLessonComplete::class)->handle($fx['enrollment'], (int) $fx['article']->getKey());

    expect((float) $fx['enrollment']->refresh()->progress_pct)->toBe(100.0);
    Event::assertDispatched(CourseCompleted::class);
})->with(['unpublished', 'deleted', 'moved']);

it('credits homework handed in BEFORE the item was published', function (): void {
    $fx = assignmentLessonCourse(itemStatus: ContentStatus::Draft);

    // Handed in from `/assignments` while the item is still a draft — and then
    // MARKED, which makes a second hand-in impossible. Without the backfill this
    // student could never complete the item once it is published.
    $submission = app(SubmitAssignment::class)->handle($fx['assignment'], $fx['student'], 'مبكّراً');
    app(GradeSubmission::class)->handle($submission, $fx['owner'], 18, 'جيّد');

    expect(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeFalse();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], fn () => app(PublishTreeNodes::class)->handle(
        $fx['course'],
        [['uuid' => $fx['item']->uuid, 'status' => 'published']],
        (int) $fx['course']->refresh()->structure_version,
    ));

    expect(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeTrue();
});

it('does not credit a student the missed sweep wrote a row for', function (): void {
    $fx = assignmentLessonCourse(itemStatus: ContentStatus::Draft);

    // The sweep's row: a submission record with no hand-in in it.
    Submission::query()->create([
        'workspace_id' => $fx['workspace']->getKey(),
        'assignment_id' => $fx['assignment']->getKey(),
        'student_user_id' => $fx['student']->getKey(),
        'state' => Submission::STATE_MISSED,
        'submitted_at' => null,
    ]);

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], fn () => app(PublishTreeNodes::class)->handle(
        $fx['course'],
        [['uuid' => $fx['item']->uuid, 'status' => 'published']],
        (int) $fx['course']->refresh()->structure_version,
    ));

    expect(assignmentItemCompleted($fx['enrollment'], $fx['item']))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Audience — the homework follows the item that places it
|--------------------------------------------------------------------------
*/

it('hides homework whose item is narrowed to another group, at all three homework doors', function (): void {
    $fx = assignmentLessonCourse();

    $visible = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): Assignment {
        $cohort = Cohort::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'created_by' => $fx['owner']->getKey(),
        ]);

        LessonCohortScope::query()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'lesson_id' => $fx['item']->getKey(),
            'cohort_id' => $cohort->getKey(),
        ]);

        // The positive control: homework of the same course with no narrowed
        // item. Without it a build that empties every list passes.
        return Assignment::factory()->published()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'created_by' => $fx['owner']->getKey(),
            'title' => 'HOMEWORK-OPEN',
        ]);
    });

    actAsAssignmentStudent($fx['student']);

    $titles = collect($this->getJson('/api/v1/assignments')->assertOk()->json('data'))->pluck('title')->all();

    expect($titles)->toContain('HOMEWORK-OPEN')
        ->and($titles)->not->toContain('HOMEWORK-PLACED');

    $this->getJson("/api/v1/assignments/{$visible->uuid}")->assertOk();
    $this->getJson("/api/v1/assignments/{$fx['assignment']->uuid}")->assertNotFound();

    $this->postJson("/api/v1/assignments/{$fx['assignment']->uuid}/submissions", ['answer_text' => 'x'])
        ->assertStatus(422);

    expect(Submission::query()->withoutWorkspaceScope()->where('assignment_id', $fx['assignment']->getKey())->count())->toBe(0);
});

/*
| Found by the stamped hand-in case above, and not specific to homework: inside a
| stamped student's own request `Enrollment::progress()` ran under another
| workspace's scope, so ANY completion at a second teacher rewrote the percentage
| from rows it could not see. The article is the plainest door onto it.
*/
it('moves a STAMPED student\'s percentage when they complete an article themselves', function (): void {
    $fx = assignmentLessonCourse();

    // Drop the homework item so the article alone is the course.
    DB::table('lessons')->where('id', $fx['item']->getKey())->delete();

    [$elsewhere] = $this->createWorkspaceWithOwner();
    $fx['student']->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();

    actAsAssignmentStudent($fx['student']);

    $this->postJson("/api/v1/enrollments/{$fx['enrollment']->uuid}/lessons/{$fx['article']->uuid}/complete")
        ->assertOk()
        ->assertJsonPath('status', 'completed');

    expect((float) $fx['enrollment']->refresh()->progress_pct)->toBe(100.0)
        ->and($fx['enrollment']->status)->toBe('completed');
});
