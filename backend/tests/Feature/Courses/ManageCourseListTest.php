<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| `/courses` as the management screen reads it: the groups, their times, the
| stage, and an envelope that says how many there are.
|
| ⚠️ THE STAGE HAD NO WRITER AT ALL. `courses.grade_level` has been fillable
| since 006 and named by the settlement-rate key, and no request, form, Action or
| seeder ever assigned it — NULL on 95 of 96 rows on the development database,
| measured 2026-09-09. A filter over it could only ever have offered one option,
| which is why the round-trip below ships with the screen that filters by it.
*/

/** @return array{0: Course, 1: mixed, 2: mixed} */
function manageListCourse(?string $title = null): array
{
    /** @var TestCase $test */
    $test = test();

    // ⚠️ The context CACHES its resolution, and the budget case below builds two
    // fixtures in one test — without this the second one's owner is read against
    // the first one's workspace and every request answers 403.
    app()->forgetInstance(WorkspaceContext::class);

    [$workspace, $owner] = $test->createWorkspaceWithOwner();

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        'title' => $title ?? 'كورس',
    ]));

    return [$course, $workspace, $owner];
}

it('sends each course its groups and the times they meet', function (): void {
    [$course, $workspace, $owner] = manageListCourse();

    $cohort = Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'name' => 'المجموعة الأولى',
    ]);

    ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        // The factory's own default builds a profile with no workspace on it,
        // which the column refuses.
        'teacher_profile_id' => TeacherProfile::factory()->create(['workspace_id' => $workspace->getKey()])->getKey(),
        'course_id' => $course->getKey(),
        'cohort_id' => $cohort->getKey(),
        'status' => ClassSessionStatus::Scheduled->value,
        // A Saturday, so the label is deterministic whatever day the suite runs.
        'starts_at' => now()->next('saturday')->setTime(16, 0),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/courses')
        ->assertOk()
        ->assertJsonPath('data.0.cohorts.0.name', 'المجموعة الأولى')
        ->assertJsonPath('data.0.cohorts.0.schedule_preview.0', 'السبت 16:00');
});

it('carries a private one-seat group nowhere near the card', function (): void {
    [$course, $workspace, $owner] = manageListCourse();

    // Born closed with the student's id on it — one named person's own room, not
    // a group anybody chooses between.
    Cohort::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'name' => 'حصص خاصة',
        'individual_for_user_id' => $owner->getKey(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/courses')
        ->assertOk()
        ->assertJsonPath('data.0.cohorts', []);
});

/*
| ⚠️ TWO SIZES AND THE FIELD ASSERTED WITH THE COUNT.
| A fixed ceiling on one course passes against an implementation that asks row by
| row; and dropping the bulk stamp produces NO N+1 at all — the key simply goes
| missing, the page gets CHEAPER, and a budget measuring queries alone reports
| the regression as an improvement.
*/
it('costs the same for eight courses as for two, with the groups still on them', function (): void {
    $cost = function (int $courses): int {
        [$first, $workspace, $owner] = manageListCourse();

        app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $courses): void {
            for ($i = 1; $i < $courses; $i++) {
                Course::factory()->create([
                    'workspace_id' => $workspace->getKey(),
                    'created_by' => $owner->getKey(),
                ]);
            }
        });

        foreach (Course::query()->withoutWorkspaceScope()->where('workspace_id', $workspace->getKey())->get() as $each) {
            Cohort::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $each->getKey(),
            ]);
        }

        Sanctum::actingAs($owner);

        // Warmed twice: the first request pays for settings and permission rows
        // a second one reads from cache, and one warm-up is not steady.
        $this->getJson('/api/v1/courses');
        $this->getJson('/api/v1/courses');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson('/api/v1/courses')->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($response->json('data.0.cohorts'))->toHaveCount(1);

        unset($first);

        return $count;
    };

    expect($cost(8))->toBeLessThanOrEqual($cost(2));
});

it('answers a total, so a page knows there is more than it received', function (): void {
    [, $workspace, $owner] = manageListCourse();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner): void {
        Course::factory()->count(2)->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
        ]);
    });

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/courses?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3);
});

it('stores and updates the stage, and refuses one that is not a stage', function (): void {
    [$course, , $owner] = manageListCourse();

    GradeLevel::query()->firstOrCreate(['slug' => 'secondary'], ['name' => 'الثانوية', 'is_active' => true]);
    $subject = Subject::factory()->create(['name' => 'الرياضيات']);

    Sanctum::actingAs($owner);

    $created = $this->postJson('/api/v1/courses', [
        'title' => 'فيزياء',
        'subject' => $subject->uuid,
        'grade_level' => 'secondary',
    ])->assertCreated();

    expect($created->json('grade_level'))->toBe('secondary');

    $this->putJson("/api/v1/courses/{$course->uuid}", ['grade_level' => 'secondary'])
        ->assertOk()
        ->assertJsonPath('grade_level', 'secondary');

    $this->putJson("/api/v1/courses/{$course->uuid}", ['grade_level' => 'year-10'])
        ->assertStatus(422);
});
