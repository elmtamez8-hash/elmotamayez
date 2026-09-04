<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Spec 023 · T004 — the index that must NOT bite the common row, and MUST bite
| the rare one.
|
| `unique(course_id, individual_for_user_id)` is the whole duplicate guard for
| FR-019د: two concurrent acceptances race into it, one wins, and the loser reads
| its own violation instead of writing a second private group for one student.
| So the index is not an optimisation to be tuned later — deleting it deletes the
| guard, and nothing else in the tree would notice.
|
| ⚠️ IT IS TESTED IN BOTH DIRECTIONS BECAUSE IT CAN FAIL IN BOTH.
| Too loose (no index) and a student collects a private group per accepted
| request. Too tight (the index written without the nullable column, or the
| column made NOT NULL with a 0 sentinel like `concept_stats.lesson_id`) and the
| SECOND ordinary group of any course is refused — which would take group
| teaching off the platform, loudly, but only for a course that already has one.
| A one-group fixture cannot see that, so this one creates five.
*/
/** @return array{0: Course, 1: mixed, 2: mixed} */
function courseForIndividualCohorts(): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        'course_type' => Course::TYPE_GROUP,
    ]));

    return [$course, $workspace, $owner];
}

function makeCohort(Course $course, ?User $student, string $name): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'name' => $name,
        'individual_for_user_id' => $student?->getKey(),
    ]);
}

it('lets a course carry many ordinary groups, because NULL never equals NULL', function (): void {
    [$course] = courseForIndividualCohorts();

    foreach (range(1, 5) as $n) {
        makeCohort($course, null, "مجموعة {$n}");
    }

    expect(Cohort::query()->withoutWorkspaceScope()->group()->where('course_id', $course->getKey())->count())->toBe(5);
});

it('refuses a second private group for the same student in the same course', function (): void {
    [$course] = courseForIndividualCohorts();
    $student = User::factory()->create();

    makeCohort($course, $student, 'حصص خاصة ١');

    expect(fn () => makeCohort($course, $student, 'حصص خاصة ٢'))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Cohort::query()->withoutWorkspaceScope()->individual()->where('course_id', $course->getKey())->count())->toBe(1);
});

it('lets two different students each hold one private group in the same course', function (): void {
    [$course] = courseForIndividualCohorts();

    makeCohort($course, User::factory()->create(), 'حصص خاصة — أ');
    makeCohort($course, User::factory()->create(), 'حصص خاصة — ب');

    expect(Cohort::query()->withoutWorkspaceScope()->individual()->where('course_id', $course->getKey())->count())->toBe(2);
});

it('lets one student hold a private group in each of two courses', function (): void {
    [$first] = courseForIndividualCohorts();
    [$second] = courseForIndividualCohorts();
    $student = User::factory()->create();

    makeCohort($first, $student, 'حصص خاصة — التفاضل');
    makeCohort($second, $student, 'حصص خاصة — الفيزياء');

    expect(Cohort::query()->withoutWorkspaceScope()->individual()->where('individual_for_user_id', $student->getKey())->count())->toBe(2);
});

it('keeps a private group out of the joinable scope even when its status says open', function (): void {
    [$course] = courseForIndividualCohorts();

    $private = makeCohort($course, User::factory()->create(), 'حصص خاصة');
    $private->status = Cohort::OPEN;
    $private->save();

    // `joinable()` answers a question about STATUS and capacity, so it says yes
    // here — correctly, and that is exactly why the public read must not lean on
    // it. Ownership is a separate predicate and `group()` is the one that
    // carries it.
    expect(Cohort::query()->withoutWorkspaceScope()->joinable()->where('course_id', $course->getKey())->count())->toBe(1)
        ->and(Cohort::query()->withoutWorkspaceScope()->joinable()->group()->where('course_id', $course->getKey())->count())->toBe(0);
});

/*
| ⛔ AND THE PICKER, WHICH LEANED ON THE STATUS AFTER ALL — from the one direction
| the scope's own docblock did not name.
|
| `CohortController::index()` omitted `group()` and filtered archived rows alone,
| and its comment above that filter deliberately KEEPS `closed` in the list («a
| closed group is a run the reader can see is happening and cannot join»). A
| private group is created `closed` and named after its owner — so one accepted
| private-session request put «حصص خاصة — <name>» into every enrolled classmate's
| picker, with her members count and, through `schedulePreviewFor()`, the times of
| her private lessons. `CohortResource` emits no `individual_for_user_id`, so no
| client could have filtered it out.
|
| ⚠️ THE READER IS A CLASSMATE, NOT A STRANGER. The endpoint already refuses
| anyone without an active enrolment in the course, which is why no cross-tenant
| test could ever have found this: everyone who saw it was entitled to be there.
*/
it('keeps another student\'s private group out of the classmates\' picker', function (): void {
    [$course, $workspace] = courseForIndividualCohorts();

    $classmate = User::factory()->create();
    $reader = User::factory()->create();

    $private = Cohort::factory()->create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        // Exactly what `ensureIndividualCohort()` writes, status included.
        'name' => 'حصص خاصة — زميلتي #'.$classmate->getKey(),
        'status' => Cohort::CLOSED,
        'individual_for_user_id' => $classmate->getKey(),
    ]);

    makeCohort($course, null, 'مجموعة السبت');

    Enrollment::create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $reader->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    Sanctum::actingAs($reader);
    // The context production gives a student: none.
    $this->asGuest();

    $names = array_map(
        static fn (array $row): string => (string) $row['name'],
        $this->getJson('/api/v1/courses/'.$course->uuid.'/cohorts')->assertOk()->json('cohorts'),
    );

    // The positive control is the same assertion's other half: dropping every
    // group would pass the negation and take group teaching off the screen.
    expect($names)->toContain('مجموعة السبت')
        ->and($names)->not->toContain($private->name);
});
