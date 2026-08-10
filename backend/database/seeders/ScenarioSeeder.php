<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Certificates\Models\CertificateTemplate;
use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Product;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every business table with a mix of states so the platform can be exercised
 * end-to-end without clicking through setup first:
 *
 * - draft / published / archived courses, sequential and free
 * - pending / approved / rejected orders (+ payment transactions)
 * - fresh / in-progress / completed enrollments with progress history
 * - in-progress / failed / passed exam attempts with answers
 * - certificates from both triggers (course completion and exam pass)
 * - CMS categories, tags and articles (published / draft / scheduled)
 * - pending / accepted / expired invitations
 * - a second workspace to verify cross-workspace isolation
 *
 * Every seeded user's password is "password".
 */
final class ScenarioSeeder extends Seeder
{
    public function run(): void
    {
        // Enrollment-from-order, certificate issuance and notification listeners are
        // ShouldQueue; run them inline so the seeded data lands complete without a worker.
        config(['queue.default' => 'sync']);

        $this->seedAcademy();
        $this->seedSoloTeacher();

        $this->command->info('Scenario data seeded — see database/seeders/ScenarioSeeder.php for the account list.');
    }

    /**
     * The main tenant: an academy with staff, students, paid + free courses,
     * the full payment → enrollment → exam → certificate flow, and CMS content.
     */
    private function seedAcademy(): void
    {
        $owner = $this->user('Nour', 'Owner', 'owner@academy.test');

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => 'Nour Academy',
                'type' => 'academy',
                'slug' => 'nour-academy',
            ]),
            $owner,
        );

        app(WorkspaceContext::class)->set($workspace);

        $teacher = $this->member($workspace, Roles::TEACHER, 'Sami', 'Teacher', 'sami@academy.test');
        $this->member($workspace, Roles::ASSISTANT_TEACHER, 'Lina', 'Assistant', 'lina@academy.test');
        $buyer = $this->member($workspace, Roles::STUDENT, 'Ali', 'Buyer', 'ali@academy.test');
        $halfway = $this->member($workspace, Roles::STUDENT, 'Mona', 'Halfway', 'mona@academy.test');
        $graduate = $this->member($workspace, Roles::STUDENT, 'Omar', 'Graduate', 'omar@academy.test');
        $browser = $this->member($workspace, Roles::STUDENT, 'Hana', 'Newcomer', 'hana@academy.test');

        $this->invitations($workspace);

        // Created before any certificate so issued certificates reference it.
        CertificateTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Default Certificate',
            'html_template' => '<div class="cert"><h1>{{ course_title }}</h1><p>{{ student_name }}</p><small>{{ certificate_number }}</small></div>',
            'defaults' => ['orientation' => 'landscape', 'accent' => '#4f46e5'],
        ]);

        $paid = $this->course($workspace, $teacher, [
            'title' => 'Laravel Mastery',
            'slug' => 'laravel-mastery',
            'description' => 'Build production APIs with Laravel: routing, Eloquent, queues and testing.',
            'price' => 49.99,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => true,
        ]);
        $paidLessons = $this->curriculum($paid, [
            ['Fundamentals', [
                ['Course Introduction', 'video', true],
                ['Installing PHP & Composer', 'article', false],
                ['Project Structure', 'pdf', false],
            ]],
            ['Going Deeper', [
                ['Eloquent Relationships', 'video', false],
                ['Queues & Jobs', 'article', false],
                ['Exercise Files', 'file', false],
            ]],
        ]);

        $free = $this->course($workspace, $teacher, [
            'title' => 'Git Basics',
            'slug' => 'git-basics',
            'description' => 'Version control from zero: commits, branches and merges.',
            'price' => 0,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => false,
        ]);
        $this->curriculum($free, [
            ['Getting Started', [
                ['What is Git', 'article', true],
                ['Your First Commit', 'video', false],
            ]],
        ]);

        $this->authoredCourse($workspace, $teacher);

        $this->course($workspace, $teacher, [
            'title' => 'Vue 3 Composition API',
            'slug' => 'vue-3-composition-api',
            'description' => 'Work in progress — not visible to students yet.',
            'price' => 39.00,
            'status' => 'draft',
            'visibility' => 'private',
            'is_sequential' => true,
        ]);

        $this->course($workspace, $teacher, [
            'title' => 'PHP 7 Legacy Track',
            'slug' => 'php-7-legacy-track',
            'description' => 'Retired course, kept for existing students.',
            'price' => 19.00,
            'status' => 'archived',
            'visibility' => 'private',
            'is_sequential' => false,
        ]);

        Product::create([
            'workspace_id' => $workspace->id, 'course_id' => $paid->id,
            'name' => 'Laravel Mastery — lifetime access', 'type' => 'course',
            'price' => 49.99, 'currency' => 'USD', 'is_active' => true,
        ]);
        Product::create([
            'workspace_id' => $workspace->id, 'course_id' => $free->id,
            'name' => 'Git Basics — free', 'type' => 'course',
            'price' => 0, 'currency' => 'USD', 'is_active' => true,
        ]);

        // Approved order → the PaymentApproved listener creates the enrollment (source=order).
        $approved = app(CreateOrder::class)->handle($paid, $buyer);
        app(ApproveOrder::class)->handle($approved, $owner);

        // Pending order awaiting review, with the matching pending transaction.
        $pending = app(CreateOrder::class)->handle($paid, $browser);
        PaymentTransaction::create([
            'workspace_id' => $workspace->id, 'order_id' => $pending->id,
            'provider' => 'manual', 'amount' => $pending->amount, 'currency' => $pending->currency,
            'status' => 'pending', 'reference' => 'bank-transfer-'.$pending->id,
            'payload' => ['bank' => 'Demo Bank', 'sender' => 'Hana Newcomer'],
        ]);

        // Rejected order.
        $rejected = app(CreateOrder::class)->handle($paid, $halfway);
        app(RejectOrder::class)->handle($rejected, $owner, 'Transfer receipt did not match the order amount.');

        // Manual enrollments in three states.
        $halfwayEnrollment = app(EnrollStudent::class)->handle($paid, $halfway);
        foreach ($paidLessons->take(3) as $lesson) {
            app(MarkLessonComplete::class)->handle($halfwayEnrollment, $lesson->id);
        }

        $graduateEnrollment = app(EnrollStudent::class)->handle($paid, $graduate);
        foreach ($paidLessons as $lesson) {
            app(MarkLessonComplete::class)->handle($graduateEnrollment, $lesson->id);
        }

        app(EnrollStudent::class)->handle($free, $browser);

        $exam = $this->exam($workspace, $paid, [
            'title' => 'Laravel Mastery — Final Exam',
            'description' => 'Covers routing, Eloquent and queues.',
            'status' => 'published',
            'passing_score' => 60,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_answers' => true,
        ]);

        $this->exam($workspace, $paid, [
            'title' => 'Mid-term Quiz (unpublished)',
            'description' => 'Draft exam, not yet visible to students.',
            'status' => 'draft',
            'passing_score' => 50,
            'max_attempts' => 1,
        ]);

        $buyerEnrollment = Enrollment::where('course_id', $paid->id)
            ->where('student_user_id', $buyer->id)
            ->firstOrFail();

        // Passed attempt → ExamPassed → certificate with reason "exam_passed".
        $passing = app(StartAttempt::class)->handle($exam, $halfway, $halfwayEnrollment);
        app(GradeAttempt::class)->handle($passing, $this->answers($exam, correct: true));

        // Failed attempt.
        $failing = app(StartAttempt::class)->handle($exam, $buyer, $buyerEnrollment);
        app(GradeAttempt::class)->handle($failing, $this->answers($exam, correct: false));

        // Attempt still in progress (no answers yet).
        app(StartAttempt::class)->handle($exam, $graduate, $graduateEnrollment);

        $profile = $this->sessions($workspace, $teacher, [$buyer, $halfway, $graduate]);

        $this->settlement($workspace, $profile, $teacher, $owner, [$buyer, $halfway]);

        // After settlement, deliberately: a credit package is priced from the
        // teacher's APPROVED rate, and with none the catalogue prices nothing and
        // the purchase screen renders an empty list (FR-021ز).
        $this->billing($workspace, $paid, [$buyer, $halfway, $graduate]);

        $this->cms($workspace, $owner);
    }

    /**
     * The four session states the e2e suite walks through.
     *
     * Without them every sessions spec skips itself on an empty schedule and
     * reports green — which is the failure mode this seeder exists to prevent,
     * and the one that let spec 004's player ship unreachable.
     *
     * @param  list<User>  $students
     */
    private function sessions(Workspace $workspace, User $teacherUser, array $students): TeacherProfile
    {
        $profile = TeacherProfile::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $teacherUser->id,
        ]);

        // 1. Upcoming with seats free — the one a student can actually book.
        $upcoming = ClassSession::factory()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'مراجعة Laravel — أسئلة مفتوحة',
            'starts_at' => now()->addDays(2)->setHour(17)->startOfHour(),
            'ends_at' => now()->addDays(2)->setHour(18)->startOfHour(),
            'seats_total' => 6,
        ]);

        app(BookSeat::class)->handle($upcoming, $students[0]);

        // 2. Full — so the seat badge has a state other than "available" to show.
        ClassSession::factory()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'ورشة الطوابير — اكتملت المقاعد',
            'starts_at' => now()->addDays(3)->setHour(19)->startOfHour(),
            'ends_at' => now()->addDays(3)->setHour(20)->startOfHour(),
            'seats_total' => 2,
            'seats_taken' => 2,
        ]);

        // 3. Finished, with a register that has both outcomes in it.
        $past = ClassSession::factory()->past()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'حصة العلاقات في Eloquent',
            'seats_total' => 6,
            'delivered_at' => now()->subDays(2),
            'billable_seats' => 2,
        ]);

        foreach ([AttendanceStatus::Present, AttendanceStatus::Absent] as $index => $status) {
            Attendance::create([
                'workspace_id' => $workspace->id,
                'class_session_id' => $past->id,
                'student_user_id' => $students[$index]->id,
                'status' => $status,
                'auto_status' => $status,
                'source' => AttendanceSource::Automatic,
                'stay_seconds' => $status === AttendanceStatus::Present ? 3480 : 0,
                'first_joined_at' => $status === AttendanceStatus::Present ? $past->starts_at : null,
                'confirmed_at' => $past->ends_at,
            ]);
        }

        // 4. A holiday, far enough out that it suspends nothing seeded above —
        // a seeder that silently cancels its own scheduled sessions leaves the
        // schedule screen empty for reasons nobody can see.
        FreezePeriod::create([
            'workspace_id' => $workspace->id,
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonth()->startOfMonth()->addDays(9)->toDateString(),
            'reason' => 'إجازة نصف العام',
            'created_by' => $teacherUser->id,
        ]);

        return $profile;
    }

    /**
     * A statement with something on it.
     *
     * A teacher opening an empty statement cannot tell "nothing is owed" from
     * "this screen is broken", and neither can an e2e spec — which is how a
     * settlement page would ship green and unreachable, exactly as 004's player
     * nearly did.
     *
     * Written directly rather than through the Actions on purpose: the Actions
     * start from a `SessionDelivered` event, and staging four different unit
     * states through real session timelines would make this seeder a second,
     * unreviewed implementation of the accrual rules. The tests own those.
     *
     * @param  list<User>  $students
     */
    private function settlement(
        Workspace $workspace,
        TeacherProfile $profile,
        User $teacherUser,
        User $approver,
        array $students,
    ): void {
        // The price, approved — the only way a rate ever comes to exist.
        // Pairs rather than a keyed map: an enum case cannot be an array key.
        foreach ([[ClassSessionType::Individual, 12_000], [ClassSessionType::Group, 4_500]] as [$type, $amount]) {
            SettlementRate::create([
                'workspace_id' => $workspace->id,
                'teacher_profile_id' => $profile->id,
                'session_type' => $type,
                'amount_minor' => $amount,
                'currency' => 'QAR',
                'effective_from' => now()->subMonths(3),
                'approved_by' => $approver->id,
            ]);
        }

        // A window that has ended, closed, and paid. Its numbers are FROZEN on
        // the row — but the rows behind them are seeded too. A period claiming
        // four units over an empty table renders a total beside an empty list,
        // which is the screen this seeder exists to prevent, not produce.
        $closedStart = CarbonImmutable::now()->subDays(60)->startOfDay();

        $closed = SettlementPeriod::create([
            'workspace_id' => $workspace->id,
            'teacher_profile_id' => $profile->id,
            'starts_on' => $closedStart,
            'ends_on' => $closedStart->addDays(29),
            'status' => SettlementPeriodStatus::Paid,
            'currency' => 'QAR',
            'units_count' => 4,
            'gross_minor' => 48_000,
            'deductions_minor' => 0,
            'carried_in_minor' => 0,
            'net_minor' => 48_000,
            'carried_out_minor' => 0,
            'closed_at' => $closedStart->addDays(30),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->teachingUnit($workspace, $profile, $students[$i % count($students)], [
                'session_type' => ClassSessionType::Individual,
                'amount_minor' => 12_000,
                'frozen_seats' => 1,
                'status' => TeachingUnitStatus::Settled,
                'delivered_at' => $closedStart->addDays($i * 7),
                'accrued_at' => $closedStart->addDays($i * 7),
                'settled_at' => $closedStart->addDays(30),
                'settlement_period_id' => $closed->id,
            ], $closed->id);
        }

        $payout = TeacherPayout::create([
            'workspace_id' => $workspace->id,
            'teacher_profile_id' => $profile->id,
            'settlement_period_id' => $closed->id,
            'amount_minor' => 48_000,
            'currency' => 'QAR',
            'reference' => 'TRF-2026-0417',
            'method' => 'bank_transfer',
            'executed_at' => $closedStart->addDays(31),
            'executed_by' => $approver->id,
        ]);

        $this->ledger($workspace, $profile, LedgerEntryType::Payout, -48_000, $closed->id, [
            'payout_id' => $payout->id,
            'reason' => 'TRF-2026-0417',
        ]);

        // The open window — one unit of each state the statement must be able to
        // render. Anything missing here is a card nobody sees until a teacher
        // hits it in production.
        $states = [
            [TeachingUnitStatus::Accrued, 12_000, ClassSessionType::Individual, 1, null],
            [TeachingUnitStatus::Accrued, 9_000, ClassSessionType::Group, 2, null],
            [TeachingUnitStatus::PendingPackage, 4_500, ClassSessionType::Group, 1, 'الباقة لم تكتمل بعد'],
            [TeachingUnitStatus::Disputed, 12_000, ClassSessionType::Individual, 1, 'الطالب يقول إن الحصة لم تُعقد'],
        ];

        foreach ($states as $index => [$status, $amount, $type, $seats, $reason]) {
            $this->teachingUnit($workspace, $profile, $students[$index % count($students)], [
                'session_type' => $type,
                'amount_minor' => $amount,
                'frozen_seats' => $seats,
                'status' => $status,
                'pending_reason' => $reason,
                'delivered_at' => now()->subDays(10 - $index),
                // A pending unit has not accrued yet — that is what pending
                // means, and a date here would make the word decorative.
                'accrued_at' => $status === TeachingUnitStatus::PendingPackage ? null : now()->subDays(10 - $index),
            ]);
        }

        // One adjustment with no unit behind it — the case a units-derived total
        // would silently omit, seeded so the screen shows it from day one.
        $this->ledger($workspace, $profile, LedgerEntryType::Deduction, -2_500, null, [
            'reason' => 'تأخّر عن حصّتين',
            'created_by' => $approver->id,
        ]);
    }

    /**
     * One unit and, when it is real money, its ledger line.
     *
     * The session is created alongside it because `class_session_id` is NOT
     * NULL, and rightly so: a teaching unit is payment for a specific hour, and
     * one that names no hour is a number nobody can check.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function teachingUnit(
        Workspace $workspace,
        TeacherProfile $profile,
        User $student,
        array $attributes,
        ?int $periodId = null,
    ): void {
        $session = ClassSession::factory()->past()->create([
            'teacher_profile_id' => $profile->id,
            'type' => $attributes['session_type'],
            'seats_total' => max(1, (int) $attributes['frozen_seats']),
            'billable_seats' => $attributes['frozen_seats'],
            'delivered_at' => $attributes['delivered_at'],
        ]);

        $unit = TeachingUnit::create(array_merge([
            'workspace_id' => $workspace->id,
            'student_user_id' => $student->id,
            'teacher_profile_id' => $profile->id,
            'class_session_id' => $session->id,
            'currency' => 'QAR',
            'basis' => SettlementBasis::FrozenSeat,
        ], $attributes));

        // Only accrued or settled work is money. A pending or disputed unit is a
        // count on the screen and nothing in the balance — which is exactly why
        // the statement's totals read the LEDGER and never the units.
        if (in_array($attributes['status'], [TeachingUnitStatus::Accrued, TeachingUnitStatus::Settled], true)) {
            $this->ledger($workspace, $profile, LedgerEntryType::Unit, (int) $attributes['amount_minor'], $periodId, [
                'teaching_unit_id' => $unit->id,
            ]);
        }
    }

    /**
     * The credit screens, with something on them (spec 006).
     *
     * Four states, because each renders differently and an empty screen is how a
     * broken one ships: a healthy positive balance, one sitting ON an alert
     * threshold, one below zero and withheld, and the catalogue that sells the
     * credits. Plus an open exam window, which is the state the exam-mode screen
     * has no other way to show.
     *
     * The workspace is switched to a DEFERRING mode here and nowhere else. In the
     * launch default nothing can go below zero (FR-014), so the withheld student
     * would be indistinguishable from a student who has simply not bought
     * anything — and the credit limit, the consent and the exam window would all
     * render as numbers that do nothing.
     *
     * @param  list<User>  $students
     */
    private function billing(Workspace $workspace, Course $course, array $students): void
    {
        app(BillingSettings::class)->save($workspace, [
            'mode' => BillingMode::ManualCollection->value,
            'cadence' => BillingCadence::Month->value,
        ]);

        $workspace->refresh();

        // The catalogue comes from the reference seeder, not from here.
        //
        // It used to be created inline, and that made the demo the only place a
        // package existed — so the screen this scenario exists to populate was
        // green in a seeded database and empty in a real one, which is the exact
        // inversion a demo seed is supposed to catch. `callOnce` rather than a
        // plain call: DatabaseSeeder already ran it, and this line is only here
        // for `db:seed --class=ScenarioSeeder` on its own.
        $this->callOnce(CreditPackageSeeder::class);

        [$healthy, $onThreshold, $withheld] = $students;

        $this->creditBalance($workspace, $course, $healthy, 10);

        // Exactly the second alert tier, so the "low balance" badge and the
        // notification ladder both have a subject.
        $this->creditBalance($workspace, $course, $onThreshold, 3);

        // Below zero and blocked. The consent is what makes the ceiling legal
        // (FR-048) — without it the limit could not have been granted, and the
        // balance could never have gone negative in the first place.
        TermsConsent::create([
            'user_id' => $withheld->id,
            'student_user_id' => $withheld->id,
            'document' => ConsentDocument::DeferredPaymentTerms->value,
            'version' => app(ConsentRegistry::class)->currentVersion(ConsentDocument::DeferredPaymentTerms),
            'ip_address' => '198.51.100.24',
            'user_agent' => 'ScenarioSeeder',
            'consented_at' => now()->subMonths(2),
        ]);

        $balance = $this->creditBalance($workspace, $course, $withheld, 2);
        $balance->forceFill(['credit_limit_credits' => 2])->save();

        // Four sessions taken against two credits and a ceiling of two: the
        // student is at the floor, which is the state the withheld badge, the 402
        // on a high-value asset and the guardian notice all describe.
        for ($source = 1; $source <= 4; $source++) {
            app(CreditLedger::class)->post(new CreditMovement(
                balance: $balance,
                type: CreditTransactionType::Consume,
                credits: -1,
                sourceType: 'class_session',
                sourceId: 900 + $source,
            ));
        }

        // Exam season, in force today. There is no stored on/off flag anywhere,
        // so a window that has passed would leave the screen showing the same
        // thing as no window at all.
        ExamModeWindow::create([
            'workspace_id' => $workspace->id,
            'starts_on' => CarbonImmutable::now()->subDay()->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(6)->toDateString(),
            'created_by' => $workspace->owner_user_id,
        ]);
    }

    /** A balance with credits in it, bought the way a purchase buys them. */
    private function creditBalance(Workspace $workspace, Course $course, User $student, int $credits): CreditBalance
    {
        $balance = app(CreditAccounts::class)->balanceFor($student, $course);

        app(CreditLedger::class)->post(new CreditMovement(
            balance: $balance,
            type: CreditTransactionType::Purchase,
            credits: $credits,
            sourceType: 'seeded_purchase',
            sourceId: (int) $balance->getKey(),
        ));

        return $balance->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ledger(
        Workspace $workspace,
        TeacherProfile $profile,
        LedgerEntryType $type,
        int $amountMinor,
        ?int $periodId,
        array $extra = [],
    ): void {
        LedgerEntry::create(array_merge([
            'workspace_id' => $workspace->id,
            'teacher_profile_id' => $profile->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => 'QAR',
            'settlement_period_id' => $periodId,
        ], $extra));
    }

    /**
     * A second, unrelated tenant. Anything visible from here while acting as a
     * Nour Academy member is a workspace-isolation bug.
     */
    private function seedSoloTeacher(): void
    {
        $owner = $this->user('Khaled', 'Solo', 'khaled@teacher.test');

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => 'Khaled Private Lessons',
                'type' => 'teacher',
                'slug' => 'khaled-private-lessons',
            ]),
            $owner,
        );

        app(WorkspaceContext::class)->set($workspace);

        $student = $this->member($workspace, Roles::STUDENT, 'Yara', 'Student', 'yara@teacher.test');

        $course = $this->course($workspace, $owner, [
            'title' => 'Arabic Calligraphy',
            'slug' => 'arabic-calligraphy',
            'description' => 'Private lessons in classical Arabic calligraphy.',
            'price' => 25.00,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => true,
        ]);
        $lessons = $this->curriculum($course, [
            ['Basics', [
                ['Holding the Qalam', 'video', true],
                ['Letter Forms', 'pdf', false],
            ]],
        ]);

        $enrollment = app(EnrollStudent::class)->handle($course, $student);
        app(MarkLessonComplete::class)->handle($enrollment, $lessons->firstOrFail()->id);

        Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Autumn schedule', 'slug' => 'autumn-schedule',
            'body' => 'New slots open on Sundays and Wednesdays.',
            'status' => 'published', 'published_at' => now()->subDay(),
            'author_id' => $owner->id,
        ]);
    }

    private function user(string $first, string $last, string $email): User
    {
        return User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'password' => 'password',
        ]);
    }

    /**
     * Create a user, attach them to the workspace and assign the spatie role
     * within that workspace's team.
     */
    private function member(Workspace $workspace, string $role, string $first, string $last, string $email): User
    {
        $user = $this->user($first, $last, $email);

        $workspace->members()->attach($user->getKey(), [
            'role' => $role,
            'joined_at' => now(),
        ]);
        $user->update(['last_workspace_id' => $workspace->getKey()]);

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($workspace->getKey());
        try {
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }

        return $user;
    }

    private function invitations(Workspace $workspace): void
    {
        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'pending.invite@academy.test',
            'role' => Roles::TEACHER, 'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'expired.invite@academy.test',
            'role' => Roles::STUDENT, 'token' => Str::random(40),
            'expires_at' => now()->subDays(3),
        ]);

        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'sami@academy.test',
            'role' => Roles::TEACHER, 'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
            'accepted_at' => now()->subDay(),
            'accepted_by' => User::where('email', 'sami@academy.test')->value('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function course(Workspace $workspace, User $author, array $attributes): Course
    {
        return Course::create(array_merge([
            'workspace_id' => $workspace->id,
            'currency' => 'USD',
            'language' => 'en',
            'created_by' => $author->getKey(),
        ], $attributes));
    }

    /**
     * A course that exercises the whole authoring surface (016).
     *
     * The other seeded courses are shaped for the STUDENT screens: articles and
     * videos, one chapter per section, everything published. Nothing in them lets
     * a person open `/manage/courses/{uuid}/content` and see what that surface
     * actually does — four families of item type, two chapters under one section,
     * a draft sitting beside its published siblings, an item pointing at an exam,
     * another at a live session, and the two dispositions a document can have.
     *
     * **The uploaded items carry asset rows whose bytes do not exist.** A demo
     * that fabricated files would be faking the one thing the media pipeline is
     * for; what this course demonstrates is the TREE. The assets are here to make
     * those items publishable and their editors complete, and playback on them
     * fails exactly as it should for a file nobody uploaded.
     */
    private function authoredCourse(Workspace $workspace, User $teacher): Course
    {
        $course = $this->course($workspace, $teacher, [
            'title' => 'Authoring Showcase',
            'slug' => 'authoring-showcase',
            'description' => 'Every supported item type, laid out the way the authoring surface builds them.',
            'price' => 0,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => true,
        ]);

        $exam = $this->exam($workspace, $course, [
            'title' => 'Showcase — end of unit',
            'status' => 'published',
            'passing_score' => 60,
        ]);

        $session = ClassSession::factory()->create([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'title' => 'Showcase — live walkthrough',
            'type' => ClassSessionType::Group,
            'starts_at' => now()->addDays(5)->setHour(18)->startOfHour(),
            'ends_at' => now()->addDays(5)->setHour(19)->startOfHour(),
        ]);

        // Two sections, four chapters — the shape a teacher actually builds, and
        // the one that makes "a draft beside its published siblings" visible.
        $plan = [
            ['Written & linked', [
                ['Inline', [
                    ['Welcome', 'article', ContentStatus::Published, []],
                    ['House rules', 'note', ContentStatus::Published, []],
                ]],
                ['Elsewhere', [
                    ['The official docs', 'link', ContentStatus::Published, ['external_url' => 'https://laravel.com/docs']],
                ]],
            ]],
            ['Uploaded & referenced', [
                ['Files', [
                    ['Lecture recording', 'video', ContentStatus::Published, []],
                    ['Audio summary', 'audio', ContentStatus::Published, []],
                    ['Handout (read here)', 'pdf', ContentStatus::Published, []],
                    ['Worksheet (download)', 'file', ContentStatus::Published, []],
                    // A draft on purpose: the surface's central rule is that
                    // nothing is visible or counted until it is published, and a
                    // course with no draft in it cannot show that rule at all.
                    ['Bonus chapter (still writing)', 'article', ContentStatus::Draft, []],
                ]],
                ['What comes next', [
                    ['Sit the unit test', 'exam', ContentStatus::Published, [
                        'reference_id' => $exam->id,
                        'exam_gate' => ExamGate::Attempt,
                    ]],
                    ['Join the walkthrough', 'live_session', ContentStatus::Published, [
                        'reference_id' => $session->id,
                    ]],
                ]],
            ]],
        ];

        $order = 1;

        foreach ($plan as $sectionIndex => [$sectionTitle, $chapters]) {
            $section = Section::create([
                'workspace_id' => $workspace->id, 'course_id' => $course->id,
                'title' => $sectionTitle, 'status' => ContentStatus::Published,
                'order' => $sectionIndex + 1,
            ]);

            foreach ($chapters as $chapterIndex => [$chapterTitle, $items]) {
                $chapter = Chapter::create([
                    'workspace_id' => $workspace->id, 'course_id' => $course->id,
                    'section_id' => $section->id, 'title' => $chapterTitle,
                    'status' => ContentStatus::Published, 'order' => $chapterIndex + 1,
                ]);

                foreach ($items as [$title, $type, $status, $extra]) {
                    $lesson = Lesson::create([
                        'workspace_id' => $workspace->id, 'course_id' => $course->id,
                        'section_id' => $section->id, 'chapter_id' => $chapter->id,
                        'title' => $title, 'type' => $type, 'status' => $status,
                        'content' => in_array($type, ['article', 'note'], true)
                            ? "Written material for: {$title}"
                            : null,
                        'order' => $order++,
                        'duration_seconds' => 0,
                        'is_preview' => false,
                        'is_free' => false,
                        ...$extra,
                    ]);

                    $this->showcaseAsset($lesson, $type);
                }
            }
        }

        return $course;
    }

    /**
     * The primary asset an uploaded item needs in order to be publishable at all.
     *
     * The two documents differ in ONE field, and it is the field 016 added: `pdf`
     * is read in the browser and `file` is downloaded. A demo carrying only one
     * value of that switch shows a control with nothing to compare it against.
     */
    private function showcaseAsset(Lesson $lesson, string $type): void
    {
        $spec = match ($type) {
            'video' => [MediaKind::Video, 'video/mp4', 'lecture.mp4', 600, false],
            'audio' => [MediaKind::Audio, 'audio/mpeg', 'summary.mp3', 300, false],
            'pdf' => [MediaKind::Document, 'application/pdf', 'handout.pdf', 0, false],
            'file' => [MediaKind::Document, 'application/pdf', 'worksheet.pdf', 0, true],
            default => null,
        };

        if ($spec === null) {
            return;
        }

        [$kind, $mime, $filename, $duration, $downloadable] = $spec;

        MediaAsset::create([
            'workspace_id' => $lesson->workspace_id,
            'owner_type' => Lesson::class,
            'owner_id' => $lesson->getKey(),
            'provider' => 'local',
            'provider_asset_id' => 'media/showcase/'.Str::uuid().'-'.$filename,
            'kind' => $kind,
            'role' => MediaRole::Primary,
            'status' => MediaAssetStatus::Ready,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => 1_048_576,
            'duration_seconds' => $duration,
            'is_downloadable' => $downloadable,
            'ready_at' => now(),
        ]);

        if ($duration > 0) {
            $lesson->update(['duration_seconds' => $duration]);
        }
    }

    /**
     * Build sections → chapters → lessons for a course. Each spec entry is
     * [section title, [[lesson title, type, is_preview], ...]].
     *
     * @param  array<int, array{0: string, 1: array<int, array{0: string, 1: string, 2: bool}>}>  $spec
     * @return Collection<int, Lesson>
     */
    private function curriculum(Course $course, array $spec): Collection
    {
        $lessons = new Collection;
        $order = 1;

        foreach ($spec as $sectionIndex => [$sectionTitle, $lessonSpecs]) {
            // Published throughout — see DemoDataSeeder for why seeded content
            // is never left as a draft.
            $section = Section::create([
                'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                'title' => $sectionTitle, 'status' => ContentStatus::Published,
                'order' => $sectionIndex + 1,
            ]);

            $chapter = Chapter::create([
                'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                'section_id' => $section->id, 'title' => $sectionTitle.' — Part 1',
                'status' => ContentStatus::Published, 'order' => 1,
            ]);

            foreach ($lessonSpecs as [$title, $type, $isPreview]) {
                $lessons->push(Lesson::create([
                    'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                    'section_id' => $section->id, 'chapter_id' => $chapter->id,
                    'title' => $title, 'type' => $type, 'status' => ContentStatus::Published,
                    'content' => $type === 'article' ? "Written material for: {$title}" : null,
                    'order' => $order++,
                    'duration_seconds' => $type === 'video' ? 600 : 0,
                    'is_preview' => $isPreview,
                    'is_free' => $isPreview,
                ]));
            }
        }

        return $lessons;
    }

    /**
     * Create an exam with a fixed question set covering both types and all difficulties.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function exam(Workspace $workspace, Course $course, array $attributes): Exam
    {
        $exam = Exam::create(array_merge([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'duration_minutes' => 30,
        ], $attributes));

        $questions = [
            ['mcq', 'easy', 'Which command starts the Laravel dev server?', [
                ['php artisan serve', true],
                ['php artisan run', false],
                ['npm start', false],
            ]],
            ['mcq', 'medium', 'Which Eloquent method eager-loads a relationship?', [
                ['with()', true],
                ['join()', false],
                ['attach()', false],
            ]],
            ['true_false', 'easy', 'Migrations can be rolled back with php artisan migrate:rollback.', [
                ['True', true],
                ['False', false],
            ]],
            ['mcq', 'hard', 'Which queue driver runs jobs immediately in-process?', [
                ['sync', true],
                ['redis', false],
                ['database', false],
            ]],
        ];

        foreach ($questions as [$type, $difficulty, $content, $options]) {
            $question = Question::create([
                'workspace_id' => $workspace->id, 'exam_id' => $exam->id,
                'type' => $type, 'difficulty' => $difficulty,
                'content' => $content, 'points' => $difficulty === 'hard' ? 2 : 1,
                'explanation' => 'Reviewed in the course material.',
            ]);

            foreach ($options as $i => [$text, $isCorrect]) {
                QuestionOption::create([
                    'workspace_id' => $workspace->id, 'question_id' => $question->id,
                    'content' => $text, 'is_correct' => $isCorrect, 'order' => $i + 1,
                ]);
            }
        }

        return $exam;
    }

    /**
     * Build a GradeAttempt payload picking either the correct or an incorrect option
     * for every question.
     *
     * @return array<int, array{question_id: int, selected_option_ids: array<int>}>
     */
    private function answers(Exam $exam, bool $correct): array
    {
        return $exam->questions()->with('options')->get()
            ->map(fn (Question $question) => [
                'question_id' => $question->id,
                'selected_option_ids' => [
                    ($question->options->first(fn (QuestionOption $o) => $o->is_correct === $correct)
                        ?? $question->options->firstOrFail())->id,
                ],
            ])
            ->all();
    }

    private function cms(Workspace $workspace, User $author): void
    {
        $news = Category::create([
            'workspace_id' => $workspace->id, 'name' => 'News', 'slug' => 'news',
        ]);
        Category::create([
            'workspace_id' => $workspace->id, 'name' => 'Releases', 'slug' => 'releases',
            'parent_id' => $news->id,
        ]);

        $tags = new Collection;
        foreach (['Laravel', 'Study Tips', 'Announcements'] as $name) {
            $tags->push(Tag::create([
                'workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name),
            ]));
        }

        $published = Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Laravel Mastery is now open for enrollment',
            'slug' => 'laravel-mastery-open',
            'body' => 'Our flagship backend track is live, with six lessons and a final exam.',
            'excerpt' => 'The flagship backend track is live.',
            'status' => 'published', 'published_at' => now()->subDays(2),
            'author_id' => $author->id, 'category_id' => $news->id,
            'seo_title' => 'Laravel Mastery — enroll today',
            'seo_description' => 'A production-focused Laravel course with a graded final exam and certificate.',
            'canonical_url' => 'https://nour-academy.test/blog/laravel-mastery-open',
        ]);
        $published->tags()->sync($tags->take(2)->pluck('id')->all());

        Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'How to study for the final exam',
            'slug' => 'how-to-study',
            'body' => 'Draft — outline only.',
            'status' => 'draft',
            'author_id' => $author->id, 'category_id' => $news->id,
        ]);

        $scheduled = Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Winter cohort dates',
            'slug' => 'winter-cohort-dates',
            'body' => 'Registration opens next month.',
            'status' => 'published', 'published_at' => now()->addWeek(),
            'author_id' => $author->id,
        ]);
        $scheduled->tags()->sync([$tags->reverse()->firstOrFail()->id]);
    }
}
