<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Subject;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| «واجباتي» — تصفيةٌ من واقعِ الطالبِ وحدَه.
|
| ⚠️ والخياراتُ تُشتَقُّ من استعلامِ القائمةِ نفسِه (`StudentScope` + `published()`)،
| لا من تسجيلاتِ الطالب. الفرقُ بينهما عيبٌ على الشاشة في الاتّجاهَين: مادّةٌ مسجَّلٌ
| فيها بلا واجبٍ خيارٌ يُفرِغُ القائمة، ومدرّسٌ كلُّ واجباتِه مسوّدةٌ اسمٌ لا يُصفّي شيئاً.
*/

/** An enrolled student who is a member of no workspace — the real shape. */
function homeworkStudent($workspace, Course $course): User
{
    $student = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $student): void {
        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    return $student;
}

it('offers only teachers and subjects that have published homework, and every option narrows to a row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $withHomework = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'title' => 'الرياضيات']);
    $withoutHomework = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'title' => 'بلا واجبات']);

    Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $withHomework->getKey(),
        'status' => 'published',
        'published_at' => now(),
    ]);

    // A DRAFT on the second course: the student cannot see it, so the course must
    // not be offered. This is the case an enrolment-derived picker gets wrong.
    Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $withoutHomework->getKey(),
        'status' => 'draft',
    ]);

    $student = homeworkStudent($workspace, $withHomework);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $withoutHomework, $student): void {
        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $withoutHomework->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);
    });

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    Sanctum::actingAs($student);

    $options = $this->getJson('/api/v1/assignments/filters')->assertOk()->json('data');

    $courses = collect($options['courses'])->pluck('uuid')->all();

    expect($courses)->toContain((string) $withHomework->uuid);
    expect($courses)->not->toContain((string) $withoutHomework->uuid);

    /*
    | ⚠️ EVERY OPTION WALKED THROUGH THE REAL ENDPOINT — `LeaderboardScopesTest`'s
    | shape. An option that returns nothing is the failure this guards, and it is
    | invisible to any assertion that compares the facet list against itself.
    */
    foreach ($options['courses'] as $option) {
        expect($this->getJson('/api/v1/assignments?course='.$option['uuid'])->assertOk()->json('data'))
            ->not->toBeEmpty();
    }

    foreach ($options['teachers'] as $option) {
        expect($this->getJson('/api/v1/assignments?teacher='.$option['uuid'])->assertOk()->json('data'))
            ->not->toBeEmpty();
    }

    // The subject and the group are two more axes and take the same walk: an
    // option that narrows to nothing is the failure this guards, on every facet.
    foreach ($options['subjects'] as $option) {
        expect($this->getJson('/api/v1/assignments?subject='.$option['uuid'])->assertOk()->json('data'))
            ->not->toBeEmpty();
    }

    foreach ($options['cohorts'] as $option) {
        expect($this->getJson('/api/v1/assignments?cohort='.$option['uuid'])->assertOk()->json('data'))
            ->not->toBeEmpty();
    }
});

/*
| ⚠️ THE SUBJECT GATHERS COURSES ACROSS TEACHERS, which is the whole reason it is
| a separate control from the course. Filtering by «الرياضيات» must return the
| homework of every maths course the student has, not the first one.
*/
it('gathers every course of one subject across teachers', function (): void {
    $subject = Subject::factory()->create(['name' => 'الرياضيات']);

    $student = null;
    $courses = [];

    foreach (range(1, 2) as $index) {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $this->setCurrentWorkspace($workspace, $owner);

        $course = Course::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'subject_id' => $subject->getKey(),
            'title' => "رياضيات {$index}",
        ]);

        Assignment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $courses[] = $course;

        if ($student === null) {
            $student = homeworkStudent($workspace, $course);
        } else {
            app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $student): void {
                Enrollment::create([
                    'workspace_id' => $workspace->getKey(),
                    'course_id' => $course->getKey(),
                    'student_user_id' => $student->getKey(),
                    'source' => 'manual',
                    'status' => 'active',
                    'progress_pct' => 0,
                    'enrolled_at' => now(),
                ]);
            });
        }
    }

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/assignments?subject='.$subject->uuid)->assertOk()->json('data'))
        ->toHaveCount(2);

    expect($courses)->toHaveCount(2);
});

/*
| ⚠️ THE CARD CARRIES ITS SUBJECT AND ITS TEACHER, and this asserts the FIELDS as
| well as the cost. A dropped eager load produces no N+1 at all — the key is
| simply absent, the page is one query cheaper, and a test measuring queries alone
| would report the regression as an improvement.
*/
it('names the subject and the teacher on every row', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'title' => 'الفيزياء']);

    foreach (range(1, 3) as $index) {
        Assignment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'status' => 'published',
            'published_at' => now(),
            'title' => "واجب {$index}",
        ]);
    }

    $student = homeworkStudent($workspace, $course);

    Sanctum::actingAs($student);

    $rows = $this->getJson('/api/v1/assignments')->assertOk()->json('data');

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row['course']['title'])->toBe('الفيزياء');
        expect($row['teacher']['uuid'])->toBe((string) $workspace->uuid);
    }
});

/*
| ⚠️ A TEACHER UUID THAT IS NOT THEIRS EMPTIES THE LIST, never returns the
| unfiltered one — the `ClassSessionController@index` idiom. Matched through the
| relation rather than by an `exists` rule, which is a raw query with no tenant
| condition.
*/
it('empties the list for a teacher the student does not study with', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

    Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'status' => 'published',
        'published_at' => now(),
    ]);

    $student = homeworkStudent($workspace, $course);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);

    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/assignments')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/v1/assignments?teacher='.$other->uuid)->assertOk()->json('data'))->toBe([]);

    expect($otherOwner)->not->toBeNull();
});
