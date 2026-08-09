<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WithWorkspace;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

uses(WithWorkspace::class)->in('Feature');

/*
 * Message templates are reference data, not fixtures.
 *
 * A dispatch with no template for its type renders nothing and is dropped with a
 * logged error (FR-037), so without this every notification assertion in the
 * suite would pass vacuously — asserting zero and getting zero. Seeded here
 * rather than per-test for the same reason roles are: it is a precondition of
 * the app running at all, not of any one scenario.
 */
uses()->beforeEach(function (): void {
    $this->seed(NotificationTemplateSeeder::class);
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Marketplace helpers
|--------------------------------------------------------------------------
|
| Shared by every Feature/Marketplace test. They live here rather than in one
| test file because a global function declared inside a test file is only
| available to the files Pest happens to load after it.
|
*/

function marketplaceWorkspace(string $name = 'Academy', bool $participates = true): Workspace
{
    /** @var Workspace $workspace */
    [$workspace] = test()->createWorkspaceWithOwner(['name' => $name]);

    $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

    return $workspace;
}

/** @param array<string, mixed> $attrs */
function marketplaceTeacher(Workspace $workspace, array $attrs = []): TeacherProfile
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn () => TeacherProfile::factory()
            ->published()
            ->scored()
            ->create([...$attrs, 'workspace_id' => $workspace->getKey()]),
    );
}

/**
 * Post a review as a marketplace student.
 *
 * The asGuest() call is not cosmetic: WorkspaceContext freezes on its first
 * resolution, the fixtures resolved it to the academy, and a student with no
 * workspace would otherwise hand spatie a stale team id.
 */
function postReview(User $student, string $teacherUuid, int $rating = 5, ?string $comment = 'ممتاز'): TestResponse
{
    Sanctum::actingAs($student);
    test()->asGuest();

    return test()->postJson("/api/v1/teachers/{$teacherUuid}/reviews", array_filter([
        'rating' => $rating,
        'comment' => $comment,
    ], fn ($value) => $value !== null));
}

/**
 * A marketplace student who has finished a course this teacher created — the
 * evidence SubmitReview demands (FR-018). The student belongs to no workspace,
 * which is exactly the shape of a real marketplace signup.
 */
function studentWhoCompletedWith(TeacherProfile $teacher): User
{
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    /** @var Workspace $workspace */
    $workspace = $teacher->workspace;

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($teacher, $student, $workspace): void {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
        ]);

        Enrollment::factory()->completed()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
        ]);
    });

    return $student;
}

/*
|--------------------------------------------------------------------------
| Billing helpers (spec 006)
|--------------------------------------------------------------------------
|
| Here rather than in one test file for the reason stated above: a function
| declared in a test file exists only for the files Pest loads after it, and
| eight suites need these.
|
*/

function billingCourse(Workspace $workspace): Course
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Course => Course::factory()->create(['workspace_id' => $workspace->getKey()]),
    );
}

/**
 * A course with a teacher profile and an approved settlement rate behind it.
 *
 * `$amountMinor = null` builds the other half of the pair: a course whose
 * teacher has NO approved rate, which prices as null and therefore sells
 * nothing. Both failure modes are silent by construction — the contract answers
 * null and the caller turns it into an empty list — so the unpriced case needs a
 * fixture as much as the priced one does.
 */
function courseWithRate(int $workspaceId, ?int $amountMinor = 5000): Course
{
    return app(WorkspaceContext::class)->forWorkspace($workspaceId, function () use ($workspaceId, $amountMinor): Course {
        $profile = TeacherProfile::factory()->create(['workspace_id' => $workspaceId]);

        if ($amountMinor !== null) {
            SettlementRate::factory()->create([
                'workspace_id' => $workspaceId,
                'teacher_profile_id' => $profile->getKey(),
                'amount_minor' => $amountMinor,
                'effective_from' => CarbonImmutable::now()->subMonth(),
            ]);
        }

        return Course::factory()->create([
            'workspace_id' => $workspaceId,
            'teacher_profile_id' => $profile->getKey(),
            'subject_id' => null,
            'grade_level' => null,
        ]);
    });
}

/**
 * A student's balance in one course, through the same lazy path production uses.
 *
 * Built with CreditAccounts rather than the factory so the account is created
 * once and shared — a factory call per balance would give one person several
 * accounts, which is the defect PlatformOwnershipTest exists to catch.
 */
function billingBalance(Workspace $workspace, User $student, ?Course $course = null): CreditBalance
{
    return app(CreditAccounts::class)->balanceFor($student, $course ?? billingCourse($workspace));
}

/** Credits added the way a purchase adds them: an entry plus a lot. */
function grantCredits(CreditBalance $balance, int $credits, string $source, ?DateTimeInterface $expiresAt = null): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Purchase,
        credits: $credits,
        sourceType: 'test_grant',
        sourceId: crc32($source),
        expiresAt: $expiresAt,
    ));

    $balance->refresh();
}

/**
 * Every string key in a nested payload, flattened.
 *
 * Recursive on purpose: a field smuggled three levels down is still on the wire,
 * and a check on the top level only would pass while the leak sat inside
 * `period` or `deductions`.
 *
 * Here rather than beside its first caller because two suites now walk payloads
 * this way — StatementPayloadTest against the teacher's, ContextIsolationTest
 * against the student's — and a helper declared in a test file only exists once
 * that particular file has been loaded.
 *
 * @return list<string>
 */
function settlementPayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...settlementPayloadKeys($child)];
    }

    return $keys;
}

/*
|--------------------------------------------------------------------------
| Delivered-session helpers (spec 006, US4)
|--------------------------------------------------------------------------
|
| Four suites charge a delivered session, and the setup is eight lines of
| broadcast plumbing that has nothing to do with what any of them asserts. Here
| for the reason stated above, and named for the whole product rather than for
| one file: `deliver()` is already taken by Settlement's PackageCompletionTest,
| and a redeclared global function in a test file is a FATAL that takes the whole
| run down — not a failing test.
|
*/

/**
 * A session on a course, with a teacher whose profile belongs to the workspace.
 *
 * The course is passed in rather than made here because billing holds a balance
 * per (student, COURSE): a fixture that invented its own course would charge a
 * balance no assertion is looking at.
 */
function billableSession(Workspace $workspace, User $teacherUser, Course $course, int $seatsTotal = 1): ClassSession
{
    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacherUser, $course, $seatsTotal): ClassSession {
        $profile = TeacherProfile::query()->where('user_id', $teacherUser->getKey())->first()
            ?? TeacherProfile::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $teacherUser->getKey(),
            ]);

        return ClassSession::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $course->getKey(),
            'seats_total' => $seatsTotal,
            'starts_at' => CarbonImmutable::now()->addMinutes(5),
            'ends_at' => CarbonImmutable::now()->addMinutes(65),
            'duration_minutes' => 60,
        ]);
    });
}

/**
 * Run the session the way a delivered one runs: the teacher joins, stays long
 * enough, and it is closed.
 *
 * Never forces `delivered_at` directly. FR-025أ makes delivery the whole
 * premise of the charge, and a fixture that writes the column skips the very
 * judgement CloseClassSession exists to make — so a regression in that judgement
 * would leave every one of these tests green.
 */
function deliverBillableSession(ClassSession $session, User $teacherUser): ClassSession
{
    app(OpenBroadcastRoom::class)->handle($session->refresh());
    app(RecordPresencePing::class)->handle($session, $teacherUser);

    Attendance::query()
        ->where('class_session_id', $session->getKey())
        ->where('student_user_id', $teacherUser->getKey())
        ->update(['stay_seconds' => 3000]);

    return app(CloseClassSession::class)->handle($session->refresh());
}
