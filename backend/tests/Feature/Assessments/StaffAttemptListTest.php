<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\GradingSettings;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| `GET /manage/attempts` — the workspace's latest handed-in papers, for staff
| holding `attempts.view.all`, confined to an assistant's courses, never another
| workspace's, and anonymous when grading is.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'الفيزياء']);
    $this->far = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'title' => 'الكيمياء']);

    $this->nearExam = Exam::factory()->create(['workspace_id' => $this->workspace->getKey(), 'course_id' => $this->near->getKey(), 'title' => 'اختبار الفيزياء']);
    $this->farExam = Exam::factory()->create(['workspace_id' => $this->workspace->getKey(), 'course_id' => $this->far->getKey(), 'title' => 'اختبار الكيمياء']);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT, User::factory()->create([
        'first_name' => 'منى',
        'last_name' => 'سالم',
    ]));
    $this->enrollment = $this->createEnrollment($this->workspace, $this->near, $this->student);
});

/** @param  array<string, mixed>  $attributes */
function handedInPaper(Workspace $workspace, Exam $exam, User $student, array $attributes = []): Attempt
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Attempt => Attempt::query()->create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => $exam->getKey(),
        'enrollment_id' => null,
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => false,
        'score' => 8,
        'max_score' => 10,
        'passed' => true,
        'random_seed' => 1,
        'started_at' => now()->subHour(),
        'submitted_at' => now()->subMinutes(30),
        ...$attributes,
    ]));
}

function confineAssistantTo(User $assistant, User $owner, ?Course $course): void
{
    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $assistant->getKey(),
        'invited_by_user_id' => $owner->getKey(),
    ]);

    if ($course !== null) {
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $assignment->getKey(),
            'course_id' => $course->getKey(),
        ]);
    }

    app()->forgetScopedInstances();
}

/** @return list<string> */
function listedAttemptUuids(): array
{
    return collect(test()->getJson('/api/v1/manage/attempts')->assertOk()->json('data'))->pluck('uuid')->all();
}

it('lists the handed-in papers newest first, with the score only when marked', function (): void {
    $older = handedInPaper($this->workspace, $this->nearExam, $this->student, [
        'enrollment_id' => $this->enrollment->getKey(),
        'submitted_at' => now()->subDays(2),
    ]);
    $pending = handedInPaper($this->workspace, $this->nearExam, $this->student, [
        'enrollment_id' => $this->enrollment->getKey(),
        'status' => Attempt::STATUS_PENDING_GRADING,
        'score' => 3,
        'submitted_at' => now()->subMinutes(5),
    ]);
    // Neither of these is a handed-in paper.
    handedInPaper($this->workspace, $this->nearExam, $this->student, ['status' => Attempt::STATUS_IN_PROGRESS, 'submitted_at' => null]);
    handedInPaper($this->workspace, $this->nearExam, $this->student, ['is_practice' => true, 'submitted_at' => now()]);

    Sanctum::actingAs($this->owner);

    $response = $this->getJson('/api/v1/manage/attempts')->assertOk();

    expect(collect($response->json('data'))->pluck('uuid')->all())->toBe([$pending->uuid, $older->uuid])
        ->and($response->json('data.0.status'))->toBe(Attempt::STATUS_PENDING_GRADING)
        // The machine-marked half of an unfinished paper is not the result.
        ->and($response->json('data.0.score'))->toBeNull()
        ->and($response->json('data.0.percentage'))->toBeNull()
        ->and($response->json('data.1.score'))->toEqual(8)
        ->and($response->json('data.1.percentage'))->toEqual(80)
        ->and($response->json('data.1.passed'))->toBeTrue()
        ->and($response->json('data.1.student.name'))->toBe('منى سالم')
        ->and($response->json('data.1.exam.title'))->toBe('اختبار الفيزياء')
        ->and($response->json('data.1.course.title'))->toBe('الفيزياء')
        // The envelope survives: a reader can tell there is a page two.
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('meta.anonymous'))->toBeFalse()
        ->and($response->json('links'))->toBeArray();
});

it('refuses a reader without attempts.view.all', function (): void {
    Sanctum::actingAs($this->student);

    $this->getJson('/api/v1/manage/attempts')->assertForbidden();
});

it('confines an assistant to the papers of their own courses', function (): void {
    $near = handedInPaper($this->workspace, $this->nearExam, $this->student, ['enrollment_id' => $this->enrollment->getKey()]);
    $far = handedInPaper($this->workspace, $this->farExam, $this->student, ['enrollment_id' => $this->enrollment->getKey()]);
    $loose = handedInPaper($this->workspace, Exam::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => null,
    ]), $this->student, ['enrollment_id' => $this->enrollment->getKey()]);

    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    // Unconfined first: every paper, the workspace-wide exam's included.
    confineAssistantTo($assistant, $this->owner, null);
    Sanctum::actingAs($assistant);
    expect(listedAttemptUuids())->toEqualCanonicalizing([$near->uuid, $far->uuid, $loose->uuid]);

    // Confined to one course: that course's papers, and never an exam with no
    // course — the null branch of `mayActOnCourse()`.
    AssistantScope::factory()->create([
        'assistant_assignment_id' => AssistantAssignment::query()->where('assistant_user_id', $assistant->getKey())->value('id'),
        'course_id' => $this->near->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($assistant->refresh());
    expect(listedAttemptUuids())->toBe([$near->uuid]);
});

it('never lists another workspace\'s papers', function (): void {
    $mine = handedInPaper($this->workspace, $this->nearExam, $this->student, ['enrollment_id' => $this->enrollment->getKey()]);

    [$other] = $this->createWorkspaceWithOwner();
    $foreign = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other): Attempt {
        $exam = Exam::factory()->create(['workspace_id' => $other->getKey()]);

        return handedInPaper($other, $exam, $this->student, ['enrollment_id' => 999]);
    });

    Sanctum::actingAs($this->owner);

    expect(listedAttemptUuids())->toBe([$mine->uuid])->not->toContain($foreign->uuid);
});

it('refuses a super admin with no workspace rather than listing every workspace', function (): void {
    handedInPaper($this->workspace, $this->nearExam, $this->student, ['enrollment_id' => $this->enrollment->getKey()]);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->getJson('/api/v1/manage/attempts')->assertForbidden();
});

it('leaves out a paper by somebody the workspace does not teach, as the row policy does', function (): void {
    $stranger = User::factory()->create();
    handedInPaper($this->workspace, $this->nearExam, $stranger);
    $taught = handedInPaper($this->workspace, $this->nearExam, $this->student);

    Sanctum::actingAs($this->owner);

    expect(listedAttemptUuids())->toBe([$taught->uuid]);
});

it('drops the student key when grading is anonymous', function (): void {
    handedInPaper($this->workspace, $this->nearExam, $this->student, ['enrollment_id' => $this->enrollment->getKey()]);
    app(GradingSettings::class)->setAnonymous($this->workspace, true);

    Sanctum::actingAs($this->owner);

    $response = $this->getJson('/api/v1/manage/attempts')->assertOk();

    expect($response->json('meta.anonymous'))->toBeTrue()
        ->and($response->json('data.0'))->not->toHaveKey('student')
        ->and($response->json('data.0.exam.title'))->toBe('اختبار الفيزياء');
});

it('costs the same for eight papers as for two, with the names still on them', function (): void {
    $cost = function (int $papers): int {
        foreach (range(1, $papers) as $index) {
            $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
            $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
            $exam = Exam::factory()->create(['workspace_id' => $this->workspace->getKey(), 'course_id' => $course->getKey()]);
            handedInPaper($this->workspace, $exam, $student, ['enrollment_id' => $this->enrollment->getKey()]);
        }

        Sanctum::actingAs($this->owner);

        // Warm until steady: the first requests pay for permission caches.
        $this->getJson('/api/v1/manage/attempts')->assertOk();
        $this->getJson('/api/v1/manage/attempts')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/manage/attempts')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A dropped eager load under `whenLoaded` makes the page CHEAPER, so the
        // fields are asserted beside the count.
        foreach ($response->json('data') as $row) {
            expect($row['student']['name'] ?? '')->not->toBe('')
                ->and($row['course']['title'] ?? '')->not->toBe('');
        }

        return $queries;
    };

    $two = $cost(2);
    DB::table('exam_attempts')->delete();
    $eight = $cost(8);

    expect($eight)->toBe($two)->and($two)->toBeLessThanOrEqual(12);
});
