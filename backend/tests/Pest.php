<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionImport;
use App\Modules\Assessments\Models\QuestionOption;
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
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
