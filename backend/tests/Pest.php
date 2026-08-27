<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Community\Support\CommunitySettings;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Jobs\MarkAbsenteesJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DataCategorySeeder;
use Database\Seeders\GamificationCatalogSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
/*
 * And the gamification catalogue, for exactly the same reason (spec 009).
 *
 * ⚠️ `AwardPoints` looks an action up by key and returns silently when there is
 * no row — an award for an undefined action is an unfilled catalogue, not an
 * error. So with no rows here NOTHING is ever awarded, and every assertion in the
 * suite about points, levels, streaks and leaderboards would pass by comparing
 * zero against zero. It is deliberately a handful of rows, because all ~1,500
 * feature tests pay for it.
 */
/*
 * And the data-protection catalogue, a third time for the third instance of one
 * mechanism (spec 013).
 *
 * ⚠️ AN EMPTY CATALOGUE MAKES THIS WHOLE PHASE ASSERT NOTHING. The consent screen
 * lists categories, the nightly sweep iterates categories, and the schema-coverage
 * test compares against categories — over zero rows all three are green and none
 * of them looked at anything. Same shape as the two above, same handful of rows,
 * same reason.
 */
uses()->beforeEach(function (): void {
    $this->seed(NotificationTemplateSeeder::class);
    $this->seed(GamificationCatalogSeeder::class);
    $this->seed(DataCategorySeeder::class);
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

    /*
    | ⚠️ THERE IS NO `rating` FIELD ANY MORE (010 · FR-031). The overall star is the
    | AVERAGE of the three axes, derived in `SubmitReview` — so the argument named
    | `$rating` here is what every caller means by it, spelled as the three axes the
    | form actually sends. Written the other way, the helper would be posting a
    | number no client can send and every assertion below it would be about an
    | endpoint that does not exist.
    */
    return test()->postJson("/api/v1/teachers/{$teacherUuid}/reviews", array_filter([
        'punctuality' => $rating,
        'clarity' => $rating,
        'engagement' => $rating,
        'comment' => $comment,
    ], fn ($value) => $value !== null));
}

/**
 * A marketplace student who has actually SAT this teacher's lessons — the evidence
 * `ReviewEligibility` demands since spec 010 (FR-030). The student belongs to no
 * workspace, which is exactly the shape of a real marketplace signup.
 *
 * ⚠️ IT USED TO BE `studentWhoCompletedWith()`, AND THE RENAME IS THE POINT. The
 * old gate was a COMPLETED ENROLMENT, which refuses a student four live lessons
 * into an active enrolment and admits one who finished a self-paced course without
 * ever meeting the teacher. A helper still called «completed» while creating
 * attendance would leave every reader of these suites believing the old rule.
 */
function studentWhoAttendedWith(TeacherProfile $teacher, ?int $sessions = null): User
{
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    /** @var Workspace $workspace */
    $workspace = $teacher->workspace;

    $count = $sessions ?? CommunitySettings::reviewMinSessions();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($teacher, $student, $workspace, $count): void {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
        ]);

        // The enrolment is still created: it is what `ConversationPolicy` and the
        // periodic review read, and a marketplace student with attendance and no
        // enrolment is a shape production never produces.
        Enrollment::factory()->completed()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            $session = ClassSession::factory()->past()->create([
                'workspace_id' => $workspace->getKey(),
                'teacher_profile_id' => $teacher->getKey(),
                'course_id' => $course->getKey(),
            ]);

            Attendance::factory()->present()->create([
                'workspace_id' => $workspace->getKey(),
                'class_session_id' => $session->getKey(),
                'student_user_id' => $student->getKey(),
            ]);
        }
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

/**
 * A student who can BOOK, for a fixture whose subject is not money.
 *
 * ⚠️ ENROLMENT IS NOT ENOUGH ANY MORE, AND THAT IS THE PRODUCT, NOT THE TEST.
 * The launch default is PREPAID_CREDITS, in which zero credits — including the
 * zero of a balance row that does not exist yet — refuses a booking. So a
 * scheduling, attendance, recording or settlement fixture that books a seat has
 * to fund the student first, exactly as a real one would have had to buy a
 * package first.
 *
 * ⚠️ AND IT IS CALLED PER FILE, NEVER FOLDED INTO `createEnrollment()`. Thirty-one
 * suites enrol a student and several of them — WithholdingTest, ExamModeTest,
 * CreditLimitTest, TeacherPanelRowTest — exist precisely to watch what an EMPTY
 * balance does. Funding every enrolment would leave those green and vacuous,
 * which is worse than leaving them red.
 */
function fundBooking(Workspace $workspace, User $student, Course $course, int $credits = 10): void
{
    grantCredits(billingBalance($workspace, $student, $course), $credits, 'fixture-funding');
}

/**
 * Sessions taught, the way delivery records them.
 *
 * `enforceFloor: false`, because that is how ChargeSessionSeats posts: the floor
 * guards the BOOKING, and a session already taught is a debt whether or not it
 * fits (R17). A helper that enforced it could not put a balance below zero,
 * which is the state half the withholding fixtures need.
 */
function consumeCredits(CreditBalance $balance, int $sessions, string $source = 'test_consume'): void
{
    foreach (range(1, $sessions) as $n) {
        app(CreditLedger::class)->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Consume,
            credits: -1,
            sourceType: $source,
            // Distinct per session: the ledger's unique key is
            // (balance, type, source_type, source_id), so a constant would make
            // every call after the first a silently ignored duplicate.
            sourceId: $n,
        ));
    }

    $balance->refresh();
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
 * Fakes the session TIMELINE and nothing else.
 *
 * ⚠️ `Queue::fake()` WITH NO ARGUMENTS IS THE TRAP THIS EXISTS TO CLOSE, and it
 * has now caught two modules. A `->delay()` runs IMMEDIATELY on the `sync`
 * connection, so without a fake `CloseClassSessionJob` fires inside
 * `OpenBroadcastRoom` and closes the session before the teacher joins — every
 * timeline collapses into one instant. But a BARE fake swallows the queued
 * listeners as well: `ChargeSeatsOnDelivery` and now `AccrueUnitsOnDelivery`,
 * which turns "ten frozen seats ⇒ ten teaching units" into a confident assertion
 * about an empty table.
 *
 * So the list is exactly the jobs a delay would misfire, plus the ingest — which
 * is dispatched from `SessionCompleted` and would otherwise interrogate a
 * provider in the middle of a test about money. Every listener runs on `sync`,
 * which is what makes the wiring part of what these tests measure.
 *
 * Named for the timeline rather than for a file: the one thing that must not
 * happen is somebody adding a class here to make their own assertion pass.
 */
/**
 * A course that runs in two groups, and a student enrolled in it.
 *
 * ⚠️ THE STUDENT LEAVES `users.last_workspace_id` NULL AND THE CONTEXT IS RESET.
 * `Sanctum::actingAs()` plus `setCurrentWorkspace()` gives a student a workspace
 * context the product NEVER gives them — nothing on a student's path writes that
 * column — and `addWorkspaceMember()` stamps it as well. A fixture that uses
 * either is measuring a person who does not exist, which is exactly how five
 * student-facing endpoints shipped dead in 017. Here the guard under test IS the
 * explicit enrolment, so a fixture with a context would prove nothing about it.
 *
 * @return array{workspace: Workspace, owner: User, student: User, course: Course, a: Cohort, b: Cohort}
 */
function cohortFixture(?int $capacityA = null, ?int $capacityB = null): array
{
    /** @var TestCase $test */
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $capacityA, $capacityB): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'course_type' => Course::TYPE_GROUP,
            'title' => 'الرياضيات',
        ]);

        Enrollment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        $make = fn (string $name, ?int $capacity): Cohort => Cohort::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'name' => $name,
            'capacity' => $capacity,
        ]);

        return [
            'course' => $course,
            'a' => $make('السبت ٤م', $capacityA),
            'b' => $make('الأحد ٦م', $capacityB),
        ];
    });

    return ['workspace' => $workspace, 'owner' => $owner, 'student' => $student, ...$built];
}

function fakeSessionTimeline(): void
{
    Queue::fake([
        CloseClassSessionJob::class,
        MarkAbsenteesJob::class,
        FreezeBillableSeatsJob::class,
        SendSessionReportsJob::class,
        IngestSessionRecordingJob::class,
    ]);
}

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

/**
 * `$count` orders and their payments, spread across BOTH of the calling test's
 * workspaces, both order kinds, three methods and three statuses.
 *
 * Here rather than beside its first caller for the reason `settlementPayloadKeys`
 * is: two suites use it — the collection report's correctness and its query
 * budget — and a helper declared in a test file only exists once that particular
 * file has been loaded.
 *
 * ⚠️ BULK INSERTED WITH `uuid` AND THE TIMESTAMPS PASSED EXPLICITLY. A bulk
 * insert boots no model, so `HasUuid` never fires and the timestamps are never
 * filled — the same fact that makes `CreditLedger::writeEntry()` pass both by
 * hand. On MySQL a missing uuid would be silently stored as `''` and every later
 * row would collide with it. Ten thousand `create()` calls would also cost more
 * than the assertion they set up.
 *
 * Expects `$this->workspace`, `$this->otherWorkspace`, `$this->owner` and
 * `$this->otherOwner` on the calling test.
 */
function seedCollection(int $count): void
{
    $test = test();

    $workspaces = [(int) $test->workspace->getKey(), (int) $test->otherWorkspace->getKey()];
    $users = [(int) $test->owner->getKey(), (int) $test->otherOwner->getKey()];
    $methods = [PaymentMethod::BankTransfer->value, PaymentMethod::MobileWallet->value, PaymentMethod::Gateway->value];
    $statuses = [PaymentStatus::Captured->value, PaymentStatus::Failed->value, PaymentStatus::Reversed->value];
    $kinds = [OrderKind::Course->value, OrderKind::Credits->value];

    $stamp = now()->subDays(3);

    for ($chunk = 0; $chunk < $count; $chunk += 500) {
        $orders = [];
        $payments = [];
        $size = min(500, $count - $chunk);

        for ($i = $chunk; $i < $chunk + $size; $i++) {
            $side = $i % 2;

            $orders[] = [
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaces[$side],
                'user_id' => $users[$side],
                'kind' => $kinds[$side],
                'amount_minor' => 1_000 + $i,
                'currency' => 'QAR',
                'provider' => 'manual',
                'status' => 'approved',
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        // The first row goes in alone to learn the id the rest will follow, so
        // the payments can point at their orders without a second read.
        $firstId = (int) DB::table('orders')->insertGetId($orders[0]);

        if ($size > 1) {
            DB::table('orders')->insert(array_slice($orders, 1));
        }

        for ($i = 0; $i < $size; $i++) {
            $index = $chunk + $i;

            $payments[] = [
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaces[$index % 2],
                'order_id' => $firstId + $i,
                'provider' => 'manual',
                'amount_minor' => 1_000 + $index,
                'currency' => 'QAR',
                'status' => $statuses[$index % 3],
                'method' => $methods[$index % 3],
                'reference' => 'REF-SEED-'.$index.'-'.$firstId,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        DB::table('payment_transactions')->insert($payments);
    }
}

/**
 * The amount held by one key of a collection-report breakdown, across currencies.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function collectionTotal(array $rows, ?string $key): int
{
    $matching = array_filter($rows, fn (array $row): bool => $row['key'] === $key);

    return (int) array_sum(array_column($matching, 'amount_minor'));
}

/**
 * A platform officer: somebody with a standing in `platform_staff` and no
 * workspace of their own.
 *
 * ⚠️ NOT `assignRole()`, WHICH CANNOT WORK HERE. spatie runs in team mode, and
 * `model_has_roles` puts `team_id` inside its primary key with NOT NULL — so a
 * teamless role has nobody to be attached to. The standing is a row of ours, and
 * `Gate::before` in TenancyServiceProvider is what turns it into permissions.
 *
 * The directory memoises per user for the life of the container, so a test that
 * grants a standing after asking a question about the same person must forget
 * them first — which is exactly what the Action does in production.
 */
function makePlatformStaff(string $role, ?User $user = null): User
{
    $user ??= User::factory()->create();

    PlatformStaff::query()->create([
        'user_id' => $user->getKey(),
        'role' => $role,
        'assigned_by' => User::factory()->create()->getKey(),
        'reason' => 'تجهيزة اختبار',
    ]);

    app(PlatformStaffDirectory::class)->forget($user);

    return $user->refresh();
}

/**
 * A tagged bank question, optionally included in an exam.
 *
 * ⚠️ IT EXISTS BECAUSE `Question::create([... 'exam_id' => $exam->id ...])` NO
 * LONGER WORKS, and that is spec 008 in one line: a question belongs to the
 * bank, an exam merely includes it. The four tags are mandatory (FR-002), so
 * every test that wants a question now needs a concept — and repeating that
 * setup in thirty places is how a mandatory tag quietly becomes optional in the
 * one place somebody forgot.
 *
 * @param  array<string, mixed>  $attributes
 */
function bankQuestion(Workspace $workspace, ?Exam $exam = null, array $attributes = []): Question
{
    $concept = Concept::query()->firstOrCreate(
        ['workspace_id' => $workspace->getKey(), 'name' => $attributes['concept_name'] ?? Concept::UNCLASSIFIED],
        ['uuid' => (string) Str::uuid()],
    );

    unset($attributes['concept_name']);

    $content = $attributes['content'] ?? 'سؤال '.Str::random(6).'؟';

    $question = Question::create(array_merge([
        'workspace_id' => $workspace->getKey(),
        'concept_id' => $concept->getKey(),
        'type' => 'mcq',
        'difficulty' => 'easy',
        'bloom_level' => 'unclassified',
        'points' => 1,
        'is_active' => true,
    ], $attributes, [
        'content' => $content,
        'content_hash' => Question::hashOf($content),
    ]));

    if ($exam !== null) {
        ExamItem::create([
            'workspace_id' => $workspace->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(),
            'order' => (int) ExamItem::query()->where('exam_id', $exam->getKey())->max('order') + 1,
        ]);
    }

    return $question;
}

/*
| Spec 008's importer fixtures.
|
| ⚠️ THEY LIVE HERE, NOT IN THE FIRST TEST FILE THAT NEEDED THEM. A helper
| defined inside one spec file is a global that exists only when that file
| happens to be loaded first — so the second file passes in a full run and fails
| the moment somebody runs it alone to debug it, with "undefined function"
| instead of the assertion they were looking at.
*/
const IMPORT_HEADER = "content,concept,difficulty,bloom_level,type,points,explanation,options,correct\n";

function importFile(string $body, bool $withBom = false): string
{
    Storage::fake('local');

    $path = 'imports/questions.csv';
    Storage::disk(config('filesystems.default'))->put(
        $path,
        ($withBom ? "\xEF\xBB\xBF" : '').IMPORT_HEADER.$body
    );

    return $path;
}

function startImport(int $workspaceId, int $userId, string $path, DuplicatePolicy $policy = DuplicatePolicy::Skip): QuestionImport
{
    return QuestionImport::create([
        'workspace_id' => $workspaceId,
        'uploaded_by' => $userId,
        'original_filename' => 'questions.csv',
        'stored_path' => $path,
        'duplicate_policy' => $policy,
    ]);
}

function mcqRow(string $content, string $concept = 'الجبر'): string
{
    return "\"{$content}\",{$concept},easy,remember,mcq,1,,صحيح|خطأ,1\n";
}

/**
 * Sits a question a known number of times, with a known number of wrong answers.
 *
 * ⚠️ IT WRITES ANSWERS THE WAY `GradeAttempt` DOES — one attempt per sitting,
 * and an answer row for every question shown. A helper that wrote several
 * answers under one attempt would still produce the right ratio, and would hide
 * the one thing the rollup's join exists for: `is_practice` lives on the
 * ATTEMPT, so a fixture with one attempt cannot tell a practice run from an
 * exam.
 *
 * @param  array<string, mixed>  $answerOverrides  applied to every answer row
 */
function sitQuestion(
    Workspace $workspace,
    Question $question,
    int $correct,
    int $wrong,
    bool $practice = false,
    array $answerOverrides = [],
): void {
    $student = User::factory()->create();

    for ($i = 0; $i < $correct + $wrong; $i++) {
        $attempt = Attempt::create([
            'workspace_id' => $workspace->getKey(),
            'exam_id' => null,
            'student_user_id' => $student->getKey(),
            'status' => Attempt::STATUS_GRADED,
            'is_practice' => $practice,
            'score' => 0,
            'max_score' => 100,
            'passed' => false,
            'random_seed' => 1,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        Answer::create(array_merge([
            'workspace_id' => $workspace->getKey(),
            'attempt_id' => $attempt->getKey(),
            'question_id' => $question->getKey(),
            'student_user_id' => $student->getKey(),
            'selected_option_ids' => [],
            'is_correct' => $i < $correct,
            'points' => $i < $correct ? 1 : 0,
        ], $answerOverrides));
    }
}

/*
|--------------------------------------------------------------------------
| Mistake-notebook helpers (spec 008 · US3)
|--------------------------------------------------------------------------
|
| ⚠️ HERE AND NOT IN A SPEC FILE. A global function declared inside a test file
| exists only for the files Pest happens to load AFTER it, so the same helper
| passes when the suite runs whole and dies with "undefined function" when one
| file is run alone.
|
*/

/**
 * One answer, written the way GradeAttempt writes them.
 *
 * @param  array<string, mixed>  $overrides
 */
function answerRow(int $workspaceId, User $student, int $questionId, bool $correct, array $overrides = []): Answer
{
    $attempt = Attempt::create([
        'workspace_id' => $workspaceId,
        'exam_id' => null,
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => $overrides['is_practice'] ?? false,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    unset($overrides['is_practice']);

    return Answer::create(array_merge([
        'workspace_id' => $workspaceId,
        'attempt_id' => $attempt->getKey(),
        'question_id' => $questionId,
        'student_user_id' => $student->getKey(),
        'selected_option_ids' => [],
        'is_correct' => $correct,
        'points' => $correct ? 1 : 0,
    ], $overrides));
}

/**
 * A bank question with one right answer and one wrong one.
 *
 * @param  array<string, mixed>  $attributes
 */
function practiceQuestion(Workspace $workspace, string $content, array $attributes = []): Question
{
    $question = bankQuestion($workspace, null, array_merge(['content' => $content], $attributes));

    QuestionOption::create([
        'workspace_id' => $workspace->getKey(),
        'question_id' => $question->getKey(),
        'content' => 'صح',
        'is_correct' => true,
        'order' => 1,
    ]);

    QuestionOption::create([
        'workspace_id' => $workspace->getKey(),
        'question_id' => $question->getKey(),
        'content' => 'خطأ',
        'is_correct' => false,
        'order' => 2,
    ]);

    return $question->load('options');
}

/**
 * A lesson this student is actively enrolled in — the entitlement US4 draws on.
 *
 * ⚠️ ENROLMENT, NOT MEMBERSHIP. `addWorkspaceMember` alone entitles a student to
 * nothing: FR-022 draws the practice pool from active enrolments, and a fixture
 * that only attached a member would make every self-exam test assert against an
 * empty pool.
 */
function enrolledLesson(Workspace $workspace, User $student): Lesson
{
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

    test()->createEnrollment($workspace, $course, $student);

    return Lesson::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Essay-grading fixtures (spec 008 · US5)
|--------------------------------------------------------------------------
|
| Here rather than in the first spec file that needed them, for the reason
| written twice above: a helper declared in a test file exists only for the
| files Pest loads after it.
*/

/**
 * A published exam holding one essay and one multiple-choice question, sat and
 * handed in by this student.
 *
 * ⚠️ IT GOES THROUGH `GradeAttempt`, NEVER THROUGH A HAND-WRITTEN ROW. The whole
 * subject of US5 is what submission does when a person is still needed —
 * `pending_grading`, no `ExamPassed`, the auto-score alone — and a fixture that
 * wrote the attempt itself would assert against its own arrangement.
 *
 * @return array{0: Exam, 1: Attempt, 2: Question}
 */
function sitEssayExam(Workspace $workspace, User $student, int $essayPoints = 10, int $passingScore = 60, int $essays = 1): array
{
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    test()->createEnrollment($workspace, $course, $student);

    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->getKey(),
        // ⚠️ ATTACHED TO THE COURSE, and it matters beyond realism: `StartAttempt`
        // records `enrollment_id` only for an exam that belongs to one, and that
        // column is what keeps a paper readable — and therefore markable — after
        // the term it was handed in during has ended.
        'course_id' => $course->getKey(),
        'status' => 'published',
        'passing_score' => $passingScore,
        'max_attempts' => 3,
    ]);

    $first = null;

    foreach (range(1, $essays) as $index) {
        $essay = bankQuestion($workspace, $exam, [
            'type' => 'essay',
            'points' => $essayPoints,
            'content' => "اشرح قانون نيوتن رقم {$index}.",
        ]);

        $first ??= $essay;
    }

    $essayQuestions = $exam->items()->pluck('question_id')->all();

    $mcq = practiceQuestion($workspace, 'واحدٌ زائد واحد يساوي اثنين؟');
    ExamItem::create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => $exam->getKey(),
        'question_id' => $mcq->getKey(),
        'order' => 2,
    ]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);

    $correct = (int) $mcq->options->firstWhere('is_correct', true)->getKey();

    $payload = [['question_id' => (int) $mcq->getKey(), 'selected_option_ids' => [$correct]]];

    foreach ($essayQuestions as $questionId) {
        $payload[] = ['question_id' => (int) $questionId, 'answer_text' => 'القوة تساوي الكتلة في العجلة.'];
    }

    app(GradeAttempt::class)->handle($attempt, $payload);

    return [$exam, $attempt->refresh(), $first];
}

/*
|--------------------------------------------------------------------------
| Homework fixtures (spec 008 · US6)
|--------------------------------------------------------------------------
*/

/**
 * A published assignment on a course this student is actively enrolled in.
 *
 * ⚠️ ENROLMENT, NOT MEMBERSHIP. The nightly sweep asks the enrolments of the
 * assignment's course who was supposed to hand in; a fixture that only added a
 * workspace member would make every sweep test assert against an empty list and
 * pass for the wrong reason.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Assignment, 1: Course}
 */
function courseAssignment(Workspace $workspace, User $author, User $student, array $attributes = []): array
{
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    test()->createEnrollment($workspace, $course, $student);

    $assignment = Assignment::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'created_by' => $author->getKey(),
        ...$attributes,
    ]);

    return [$assignment, $course];
}

/*
|--------------------------------------------------------------------------
| Unlock-gate fixtures (spec 008 · US7)
|--------------------------------------------------------------------------
*/

/**
 * Two sessions of one course, the first already taught and the second ahead.
 *
 * ⚠️ THE FIRST MUST BE `completed` AND IN THE PAST. "Previous" is the latest
 * COUNTABLE earlier session, so a scheduled one is not a predecessor at all —
 * and a fixture that left it scheduled would make every gate test assert against
 * the no-previous branch and pass for the wrong reason.
 *
 * @return array{0: ClassSession, 1: ClassSession, 2: Course}
 */
function gatedPair(Workspace $workspace, User $student): array
{
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    test()->createEnrollment($workspace, $course, $student);

    $first = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'status' => ClassSessionStatus::Completed,
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHour(),
    ]);

    $second = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'status' => ClassSessionStatus::Scheduled,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
    ]);

    return [$first, $second, $course];
}

/** The register row a gate reads. */
function attendanceRow(Workspace $workspace, ClassSession $session, User $student, AttendanceStatus $status): Attendance
{
    return Attendance::create([
        'workspace_id' => $workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => $status,
        'source' => 'automatic',
    ]);
}

/*
|--------------------------------------------------------------------------
| Query budgets
|--------------------------------------------------------------------------
*/

/**
 * Run a closure and report how many queries it cost.
 *
 * ⚠️ IT LIVES HERE BECAUSE IT HAS TWO CALLERS IN TWO MODULES. It was declared
 * inside `LiveSessions/QueryBudgetTest.php`, which makes it a global that exists
 * only when that file happens to be loaded first — so the second file passes in
 * a full run and dies with "undefined function" the moment somebody runs it
 * alone to debug it. The same reason the import fixtures are up there.
 *
 * @return array{0: int, 1: mixed}
 */
function countingQueries(callable $work): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = $work();

    if (getenv('DUMP_QUERIES') !== false) {
        foreach (DB::getQueryLog() as $q) {
            fwrite(STDERR, $q['query']."\n");
        }
    }

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $result];
}

/*
|--------------------------------------------------------------------------
| WhatsApp channel (spec 020)
|--------------------------------------------------------------------------
|
| ⚠️ HERE AND NOT IN A TEST FILE, for the reason written above countingQueries():
| a function declared inside one test file is a global that exists only when that
| file happens to be loaded first, so the second file passes in a full run and
| dies with "undefined function" the moment somebody runs it alone to debug it.
| Four files use these.
*/

function configureWhatsApp(bool $enabled = true): void
{
    config()->set('notifications.whatsapp.enabled', $enabled);
    config()->set('notifications.whatsapp.api_key', $enabled ? 'test-key' : null);
    config()->set('notifications.whatsapp.base_url', 'https://provider.test');
    config()->set('notifications.whatsapp.auth_header', 'X-Test-Key');
}

/**
 * Flip one template to approved.
 *
 * The seeder ships every WhatsApp row `pending`, which is the truth about a
 * process that happens at the provider and takes days — so a test of the happy
 * path has to state that the approval happened, exactly as an operator will.
 */
function approveWhatsAppTemplate(string $type): void
{
    MessageTemplate::query()
        ->where('type', $type)
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->update(['provider_approval_status' => MessageTemplate::APPROVAL_APPROVED]);
}

function withVerifiedWhatsApp(string $number = '+97433123456'): User
{
    $user = User::factory()->create();

    ContactVerification::query()->create([
        'user_id' => $user->getKey(),
        'channel' => NotificationChannel::WhatsApp->value,
        'contact_value' => $number,
        'code_hash' => 'x',
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'verified_at' => now(),
    ]);

    return $user;
}

function envelopeFor(User $user, NotificationType $type = NotificationType::SessionReport): NotificationEnvelope
{
    return new NotificationEnvelope(
        recipient: $user,
        type: $type,
        titleAr: 'عنوان',
        bodyAr: 'نصّ',
        actionUrl: null,
        payload: [
            'title' => 'حصّة الجبر',
            'student_name' => 'سلمى',
            'status' => 'حاضرة',
            'minutes' => '45',
            'note' => 'أداء جيّد',
        ],
        notificationUuid: 'n-1',
    );
}

/*
|--------------------------------------------------------------------------
| Broadcast channel authorisation (spec 010)
|--------------------------------------------------------------------------
|
| ⚠️ THE TEST BROADCASTER AUTHORISES NOTHING, AND AN ASSERTION WRITTEN AGAINST
| IT IS VACUOUSLY GREEN. `phpunit.xml` sets `BROADCAST_CONNECTION=null`, and
| `NullBroadcaster::auth()` is an empty method body — no channel callback is ever
| consulted, so `/api/broadcasting/auth` answers 200 for every channel name and
| every user, including a stranger asking for someone else's private conversation.
| `SC-005` says «zero successful subscriptions to a channel that was not
| authorised»; measured on the default connection, that criterion cannot fail.
|
| So the helper switches to the pusher driver, whose `auth()` runs
| `verifyUserCanAccessChannel()` — the callbacks in `routes/channels.php` — before
| signing anything. The credentials are fabricated because none of this touches a
| network: `authorizeChannel()` is an HMAC over the socket id, computed locally.
| `pusher/pusher-php-server` ships with reverb, so nothing new is installed for it.
|
| Here rather than inside a test file for the reason written above
| `countingQueries()`: a function declared in one test file is a global that
| exists only when that file happens to load first.
*/
function subscribeToChannel(string $channel, string $socketId = '1234.5678'): TestResponse
{
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.secret' => 'test-secret',
        'broadcasting.connections.pusher.app_id' => 'test-app',
    ]);

    app()->forgetInstance(BroadcastManager::class);
    Broadcast::clearResolvedInstances();

    /*
    | ⚠️ AND THE CHANNELS HAVE TO BE REGISTERED AGAIN ON THE NEW DRIVER.
    | `Broadcast::channel()` is a `__call` proxy to `$this->driver()->channel()` —
    | the callbacks live on the BROADCASTER INSTANCE, not on the manager. So a
    | freshly resolved driver knows no channel names at all, and every private
    | subscription is refused with a 403 that looks exactly like a failed
    | authorisation. Re-requiring the shipped file is what keeps this helper
    | measuring `routes/channels.php` rather than a copy of it.
    */
    require base_path('routes/channels.php');

    return test()->postJson('/api/broadcasting/auth', [
        'channel_name' => $channel,
        'socket_id' => $socketId,
    ]);
}

/*
|--------------------------------------------------------------------------
| Report-card fixtures (spec 010 · US5)
|--------------------------------------------------------------------------
|
| ⚠️ HERE AND NOT IN A SPEC FILE. A function declared inside a test file exists
| only for the files Pest happens to load AFTER it, so the same helper passes
| when the suite runs whole and dies with "undefined function" the moment one
| file is run alone to debug it. The same rule already moved `countingQueries()`
| and the import fixtures up here.
|
*/

/** A completed session inside the period, with the student's register row. */
function periodSession(Workspace $workspace, Course $course, User $student, AttendanceStatus $status): ClassSession
{
    $session = ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'status' => ClassSessionStatus::Completed,
        'starts_at' => '2026-08-10 09:00:00',
        'ends_at' => '2026-08-10 10:00:00',
    ]);

    attendanceRow($workspace, $session, $student, $status);

    return $session;
}

/** One graded, non-practice attempt inside the period. */
function periodAttempt(Workspace $workspace, User $student, float $score, float $max, bool $practice = false): Attempt
{
    $exam = Exam::factory()->create(['workspace_id' => $workspace->getKey()]);

    return Attempt::create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => $exam->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => $practice,
        'score' => $score,
        'max_score' => $max,
        'passed' => true,
        'random_seed' => 1,
        'started_at' => '2026-08-12 09:00:00',
        'submitted_at' => '2026-08-12 10:00:00',
    ]);
}

/**
 * A guardian of this student, authorised for exactly these permissions.
 *
 * Moved out of `PeriodicReviewTest.php` when the report card needed it too — a
 * helper declared in a test file exists only for the files loaded after it.
 *
 * @param  list<GuardianPermission>  $permissions
 */
function guardianOf(User $student, array $permissions): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => $student->name,
        'relation_type' => RelationType::Parent->value,
        'permissions' => array_map(
            fn (GuardianPermission $permission): string => $permission->value,
            $permissions,
        ),
        'status' => RelationStatus::Active->value,
    ]);

    return $guardian;
}
