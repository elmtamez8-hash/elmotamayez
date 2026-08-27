<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| `?course={uuid}` on the three lists the course page's tabs read (US2 · FR-017 ·
| FR-018 · FR-020). All three had no course filter at all, and all three carry the
| column.
|
| ⚠️ AN UNKNOWN UUID MATCHES **NOTHING**, NOT EVERYTHING. The filter is applied
| through the relation, so a value it cannot resolve returns an empty list rather
| than the unfiltered one — silently ignoring a filter is how a tab labelled
| «اختبارات هذه المادّة» shows a student every paper on the platform. Same idiom,
| character for character, as `ClassSessionController@index`, which is the fourth
| list the same page reads.
|
| ⚠️ AND THE TEACHER'S READING IS TESTED SEPARATELY, BECAUSE IT IS THE ONE THAT
| BREAKS. `ExamController@index` ORs the draft branch onto the status condition,
| and an un-parenthesised OR with a filter appended after it reads as
| «published OR (draft AND course-match)» — every published exam in the workspace
| escapes the filter for anybody holding EXAMS_VIEW. A student fixture cannot see
| that: the student never reaches the OR.
*/

/** @return array{workspace: Workspace, owner: User, student: User, x: Course, y: Course} */
function tabFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student): array {
        $make = fn (string $title): Course => Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'title' => $title,
        ]);

        $x = $make('الرياضيات');
        $y = $make('الفيزياء');

        foreach ([$x, $y] as $course) {
            $enrollment = Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            Exam::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'title' => 'اختبار '.$course->title,
            ]);

            Assignment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $owner->getKey(),
                'title' => 'واجب '.$course->title,
                'points' => 10,
                'submission_type' => 'text',
                'status' => 'published',
                'published_at' => now(),
            ]);

            Certificate::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'student_user_id' => $student->getKey(),
                'student_display_name' => 'طالب',
                'certificate_number' => 'C-'.$course->getKey(),
                'verification_code' => 'V-'.$course->getKey(),
                'issue_reason' => 'course_completed',
                'issued_at' => now(),
            ]);
        }

        // A draft in the OTHER course, so the teacher's branch has something to
        // leak that the student's branch never sees.
        Exam::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $y->getKey(),
            'status' => 'draft',
            'title' => 'مسوّدة الفيزياء',
        ]);

        return compact('x', 'y');
    });

    return ['workspace' => $workspace, 'owner' => $owner, 'student' => $student, ...$built];
}

/** Every title in a list response, however the module wraps its collection. */
function tabTitles(array $payload): array
{
    $rows = $payload['data'] ?? $payload;

    return array_map(static fn (array $row): string => (string) $row['title'], array_values($rows));
}

beforeEach(function (): void {
    $this->fx = tabFixture();
});

it('narrows the three lists to one course for the student who owns the page', function (): void {
    Sanctum::actingAs($this->fx['student']);
    $this->asGuest();

    $course = $this->fx['x']->uuid;

    expect(tabTitles($this->getJson('/api/v1/exams?course='.$course)->assertOk()->json()))
        ->toBe(['اختبار الرياضيات']);

    expect(tabTitles($this->getJson('/api/v1/assignments?course='.$course)->assertOk()->json()))
        ->toBe(['واجب الرياضيات']);

    // Certificates carry no title of their own; the course they belong to is
    // what the tab shows and what identifies the row.
    $certificates = $this->getJson('/api/v1/certificates?course='.$course)->assertOk()->json();
    $rows = $certificates['data'] ?? $certificates;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['course_title'] ?? null)->toBe('الرياضيات');
});

it('answers a uuid it cannot resolve with an empty list rather than the whole one', function (): void {
    Sanctum::actingAs($this->fx['student']);
    $this->asGuest();

    $stranger = (string) Str::uuid();

    foreach (['exams', 'assignments', 'certificates'] as $list) {
        $payload = $this->getJson("/api/v1/{$list}?course={$stranger}")->assertOk()->json();

        expect($payload['data'] ?? $payload)->toBeEmpty();
    }
});

/*
| ⚠️ THE READER WHO CAN SEE DRAFTS IS THE READER THE FILTER BREAKS FOR.
|
| Without the status disjunction wrapped in its own group, `?course=` lands
| beside an OR and binds to one arm only. The tell is not the draft — it is the
| PUBLISHED exam of the other course coming back under a filter that named this
| one.
*/
it('holds the filter for a teacher who may also see drafts', function (): void {
    Sanctum::actingAs($this->fx['owner']);
    $this->setCurrentWorkspace($this->fx['workspace'], $this->fx['owner']);

    $titles = tabTitles(
        $this->getJson('/api/v1/exams?course='.$this->fx['x']->uuid)->assertOk()->json(),
    );

    expect($titles)->toBe(['اختبار الرياضيات'])
        ->and($titles)->not->toContain('اختبار الفيزياء')
        ->and($titles)->not->toContain('مسوّدة الفيزياء');
});

it('leaves an unfiltered list alone', function (): void {
    Sanctum::actingAs($this->fx['student']);
    $this->asGuest();

    // The control: without the parameter both courses are still there, so the
    // assertions above are narrowing rather than emptying.
    expect(tabTitles($this->getJson('/api/v1/exams')->assertOk()->json()))
        ->toContain('اختبار الرياضيات')
        ->toContain('اختبار الفيزياء');
});
