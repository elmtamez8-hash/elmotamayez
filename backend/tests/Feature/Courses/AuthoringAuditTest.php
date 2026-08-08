<?php

declare(strict_types=1);

use App\Modules\Courses\Actions\ManageChapters;
use App\Modules\Courses\Actions\ManageLessons;
use App\Modules\Courses\Actions\ManageSections;
use App\Modules\Courses\Actions\PublishTreeNodes;
use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\DTOs\LessonData;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Settlement\Support\SettlementAuditSubjects;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Every authoring verb leaves a record of who did it and when (`FR-056`).
 *
 * A course tree is written by three parties — the teacher, the 005 listener that
 * publishes recordings, and any co-teacher with `LESSONS_MANAGE` — and the
 * questions asked afterwards are always the same two: who moved this, and when.
 * `activity_log` already answers them for courses, exams and certificates; the
 * tree was the part with no answer at all.
 *
 * The assertion is per verb, because the failure mode is per verb: a trait added
 * to a class covers nothing on its own, and the one method whose call was
 * forgotten is invisible until an auditor asks about exactly that action.
 */
function auditedTree(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
        ]);

        return [$course, $owner];
    });
}

/** @return array<int, object> */
function auditRows(string $description): array
{
    return DB::table('activity_log')->where('description', $description)->get()->all();
}

it('records who created, renamed and deleted each kind of node', function (): void {
    [$course, $owner] = auditedTree();

    $section = app(ManageSections::class)->create($course, 'الوحدة');
    $chapter = app(ManageChapters::class)->create($course, $section, 'الفصل');
    $lesson = app(ManageLessons::class)->create($course, $chapter, LessonData::fromArray([
        'chapter_uuid' => $chapter->uuid,
        'title' => 'الدرس',
        'type' => 'article',
        'content' => 'نصّ',
    ]));

    $created = auditRows('created');

    expect($created)->toHaveCount(3)
        ->and(collect($created)->pluck('subject_type')->all())
        ->toBe([Section::class, Chapter::class, Lesson::class])
        // The causer, because "when" without "who" answers half the question.
        ->and(collect($created)->pluck('causer_id')->unique()->all())->toBe([$owner->id]);

    app(ManageSections::class)->rename($section, 'الوحدة الأولى');
    app(ManageChapters::class)->rename($chapter, 'الفصل الأول');

    expect(auditRows('renamed'))->toHaveCount(2);

    app(ManageLessons::class)->delete($lesson->refresh());

    $deleted = auditRows('deleted');

    // Written BEFORE the row goes: after it, `performedOn` would be recording a
    // subject that no longer resolves — an audit line about nothing in
    // particular, on the one action an auditor is most likely to be asking about.
    expect($deleted)->toHaveCount(1)
        ->and((int) $deleted[0]->subject_id)->toBe((int) $lesson->getKey());
});

it('records a publish on each node and a reorder on the course', function (): void {
    [$course] = auditedTree();

    $section = app(ManageSections::class)->create($course, 'الوحدة');
    $chapter = app(ManageChapters::class)->create($course, $section, 'الفصل');

    app(PublishTreeNodes::class)->handle($course, [
        ['uuid' => $section->uuid, 'status' => 'published'],
        ['uuid' => $chapter->uuid, 'status' => 'published'],
    ], (int) $course->refresh()->structure_version);

    // One per node, not one per batch: FR-056 puts the record on the item
    // concerned, and a single line naming two uuids cannot be read from either
    // item's own history.
    expect(auditRows('published'))->toHaveCount(2);

    $second = app(ManageChapters::class)->create($course, $section, 'الفصل الثاني');

    app(ReorderTreeNodes::class)->handle(
        $course,
        $section->chapters()->getQuery(),
        [$second->uuid, $chapter->uuid],
        (int) $course->refresh()->structure_version,
    );

    $reordered = auditRows('reordered');

    // The subject is the COURSE: a reorder is one fact about one sibling group,
    // and a row per moved node would bury that under its mechanics.
    expect($reordered)->toHaveCount(1)
        ->and($reordered[0]->subject_type)->toBe(Course::class);
});

it('keeps authoring records out of the settlement audit', function (): void {
    [$course] = auditedTree();

    app(ManageSections::class)->create($course, 'الوحدة');

    // `activity_log` is one shared table, and the settlement audit reads it. It
    // filters by asking for six subject types rather than by removing rows —
    // which is what makes adding a seventh kind of subject safe by construction
    // instead of safe until somebody forgets.
    expect(SettlementAuditSubjects::types())->not->toContain(Section::class)
        ->and(SettlementAuditSubjects::types())->not->toContain(Lesson::class);
});
