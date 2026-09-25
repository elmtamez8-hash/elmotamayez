<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\ClaimSubscriptionSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Jobs\ExpireSubscriptionsJob;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\PlanChangeRequest;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| Spec 027 · US3 — one press produces an enrolment, a group, seats and a message.
|
| ⚠️ THE OFFICER OWNS A DIFFERENT WORKSPACE FROM THE ONE BEING APPROVED. That one
| fixture line is what exposed all five layers of the 024 defect, and this file
| exercises a platform-permission WRITE, where the same fallback applies.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->otherWorkspace] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published', 'title' => 'الفيزياء ٣'])->save();

    $this->plan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي',
        'duration_days' => 30,
        'price_minor' => 45_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $this->cohort = cohortNamed('مجموعة السبت');

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->otherWorkspace->getKey()])->save();
});

function cohortNamed(string $name, ?int $capacity = null): Cohort
{
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        fn (): Cohort => Cohort::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'course_id' => $test->course->getKey(),
            'name' => $name,
            'capacity' => $capacity,
            'created_by' => $test->teacher->getKey(),
        ]),
    );
}

function orderForCohort(?Cohort $cohort = null): Order
{
    $test = test();

    return app(PurchaseSubscription::class)->handle(
        $test->student,
        (string) $test->plan->uuid,
        'cohort',
        (string) ($cohort ?? $test->cohort)->uuid,
    );
}

function approveIt(Order $order): Order
{
    return app(ApproveOrder::class)->handle($order, test()->officer);
}

it('turns one approval into a subscription, an enrolment, a group and a message', function (): void {
    $order = orderForCohort();

    approveIt($order);

    $subscription = Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->first();

    expect($subscription)->not->toBeNull()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->where('course_id', $this->course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists())->toBeTrue()
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('cohort_id', $this->cohort->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->exists())->toBeTrue()
        ->and(Notification::query()
            ->where('recipient_user_id', $this->student->getKey())
            ->where('type', NotificationType::SubscriptionActivated->value)
            ->exists())->toBeTrue();
});

it('says out loud that no lesson is scheduled yet, rather than dropping the line', function (): void {
    // FR-029أ — an omitted line reads as a fault: the student refreshes, finds
    // nothing, and asks whether their payment went through.
    approveIt(orderForCohort());

    $notification = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::SubscriptionActivated->value)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body)->toContain('لم تُجدول حصة قادمة بعد');
});

it('refuses the APPROVAL when the group filled up after the order, writing nothing', function (): void {
    /*
    | FR-026. Refusing later — in the queued activation — is literally the state
    | the requirement exists to prevent: an approved order, money taken, and a
    | student in no group, with nothing on the officer's screen.
    */
    $order = orderForCohort();

    $this->cohort->forceFill(['capacity' => 1, 'members_count' => 1])->save();

    expect(fn () => approveIt($order))->toThrow(DomainException::class);

    $order->refresh();

    expect($order->status)->toBe('pending')
        ->and($order->approved_at)->toBeNull()
        ->and(Subscription::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->exists())->toBeFalse()
        ->and(Enrollment::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())->exists())->toBeFalse();
});

it('lets a renewal into the student’s OWN full group through', function (): void {
    /*
    | ⚠️ THE CASE A BARE `isJoinable()` PRE-CHECK REFUSES, AND IT IS THE ORDINARY
    | ONE. A renewing student's group is full OF THEM AND THEIR CLASSMATES, so
    | asking joinability before membership leaves their paid, approved order
    | `pending` for ever under «هذه المجموعة لم تعد متاحة» — US4·4 inverted.
    */
    approveIt(orderForCohort());

    $this->cohort->forceFill(['capacity' => 1])->save();
    $this->cohort->refresh();

    $renewal = orderForCohort();

    approveIt($renewal);

    expect($renewal->refresh()->status)->toBe('approved')
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('cohort_id', $this->cohort->getKey())
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->count())->toBe(1);
});

it('refuses the approval rather than moving a student who joined another group', function (): void {
    /*
    | Neither obvious answer is acceptable: `JoinCohort` would throw AFTER the
    | money committed, and the membership writer would move them out of the group
    | they are in with nobody deciding it — a transfer bought for the price of the
    | cheapest plan, filed in the log as a join.
    */
    $order = orderForCohort();

    $elsewhere = cohortNamed('مجموعة الأحد');

    CohortMembership::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'cohort_id' => $elsewhere->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'joined_at' => now(),
    ]);

    expect(fn () => approveIt($order))->toThrow(DomainException::class);

    expect($order->refresh()->status)->toBe('pending')
        ->and(CohortMembership::query()->withoutWorkspaceScope()
            ->where('student_user_id', $this->student->getKey())
            ->whereNull('closed_at')
            ->where('cohort_id', $elsewhere->getKey())
            ->exists())->toBeTrue();
});

it('opens the private-session door instead of a group when that is what was bought', function (): void {
    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 60_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    $order = app(PurchaseSubscription::class)->handle($this->student, (string) $plan->uuid, 'private');

    approveIt($order);

    $notification = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::SubscriptionActivated->value)
        ->first();

    expect(Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->where('course_id', $this->course->getKey())
        ->exists())->toBeTrue()
        ->and($notification)->not->toBeNull()
        ->and($notification->body)->toContain('مواعيد مدرّسك');
});

it('prints the next lesson in the platform timezone, never as a raw timestamp', function (): void {
    /*
    | ٠٢٧ · T075 — `2026-09-26T14:00:00+00:00` وصلَ فعلاً إلى جرسِ طالبٍ على
    | الإنتاجِ في ٢٠٢٦-٠٩-٢٠، وفي فقرةٍ عربيّةٍ يُعيدُ الـbidi ترتيبَه إلى
    | `26T14:00:00+00:00-09-2026`: غيرُ مقروءٍ لا قبيحٌ فحسب. والسطرُ الذي قبلَه
    | مباشرةً كانَ يقولُ «السبت 17:00» — حصّةٌ واحدةٌ بشكلَينِ أحدُهما نصُّ آلة.
    |
    | ⛔ والموعدُ هنا مقصودٌ ولا يُستبدَلُ بموعدٍ «أبسط»: ٢٢:٣٠ بـUTC هي ٠١:٣٠ من
    | اليومِ **التالي** بتوقيتِ قطر، فالتحويلُ الساقطُ يُخطئُ اليومَ لا الساعةَ
    | وحدَها — «السبت» عن حصّةِ الأحد. والساعةُ رقمٌ قد يُشكَّكُ فيه، واليومُ
    | يُقرَأُ حقيقة. وهي الحالةُ التي تصفُها `schedulePreviewFor()` في تعليقِها
    | منذُ ٢٠٢٦-٠٩-١٥، بينما بقيَتْ أختُها `nextSessionFor()` تُرجِعُ UTC.
    */
    app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): void {
        ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => TeacherProfile::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
            ])->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => $this->cohort->getKey(),
            'starts_at' => CarbonImmutable::parse('2026-10-03 22:30'),
            'ends_at' => CarbonImmutable::parse('2026-10-03 23:30'),
            'status' => ClassSessionStatus::Scheduled,
        ]);
    });

    approveIt(orderForCohort());

    $body = Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::SubscriptionActivated->value)
        ->first()?->body;

    /*
    | ⚠️ والنفيانِ ليسا زينةً حولَ التوكيدِ الأوّل: الأوّلُ يسقطُ حينَ يعودُ
    | التحويلُ مفقوداً، والثاني وحدَه يسقطُ حينَ يعودُ الطابعُ الخامُّ كما كان.
    */
    expect($body)->toContain('الأحد 2026-10-04 01:30')
        ->and($body)->not->toContain('2026-10-03')
        ->and($body)->not->toContain('+00:00');
});

it('names the teacher the buyer was shown, not the workspace owner', function (): void {
    /*
    | ٠٢٧ · T075 — قرارُ المالكِ ٢٠٢٦-٠٩-٢٠.
    |
    | ⛔ الفخُّ أنّ المالكَ والمدرّسَ شخصٌ واحدٌ في خمسِ ورشاتٍ من ستٍّ على
    | الإنتاج، فتجهيزةٌ «طبيعيّة» تمرُّ خضراءَ فوقَ العطبِ كاملاً. الحالةُ التي
    | تكشفُه هي الأكاديميّةُ: يملكُها شخصٌ ويُدرِّسُ فيها آخر — وهي الشكلُ الذي
    | يُباعُ فيه هذا المنتَج، لا حالةٌ شاذّة. فالكورسُ هنا يُنشِئُه مدرّسٌ **غيرُ**
    | مالكِ الورشة، وهو ما تقرؤُه شاشةُ الطالبِ (`$course->creator`).
    */
    $employed = User::factory()->create(['first_name' => 'سامي', 'last_name' => 'المدرّس']);

    $this->course->forceFill(['created_by' => $employed->getKey()])->save();

    $order = orderForCohort();

    expect($order->metadata['teacher_name'])->toBe($employed->name)
        // ولا يكفي التوكيدُ على الاسمِ الجديد: لو عادَ القارئُ إلى المالكِ
        // لظلَّ الحقلُ مملوءاً باسمٍ يبدو سليماً، وهذا هو ما شُحِنَ فعلاً.
        ->and($order->metadata['teacher_name'])->not->toBe($this->teacher->name)
        ->and($order->metadata['teacher_uuid'])->toBe((string) $employed->uuid);
});

it('starts a renewal where the running month ends, not today', function (): void {
    /*
    | ٠٢٧ · T075 — قرارُ المالكِ ٢٠٢٦-٠٩-٢٠.
    |
    | ⛔ المقيسُ على الإنتاج: اشتراكٌ سارٍ إلى ٢٠٢٦-١٠-١٦، وتجديدٌ في العشرينَ
    | أُعطِيَ نافذةً من **ذلك اليوم** — ٢٦ يوماً متداخلةً دُفِعَتْ مرّتَين، أي
    | ٦٠٠ ر.ق مقابلَ ٣٤ يوماً لا ٦٠.
    |
    | ⚠️ والتوكيدُ على يومِ البدءِ لا على عددِ الأيّام: نافذةُ الثلاثينَ صحيحةٌ
    | في الحالتَين، والخطأُ كلُّه في المبدأ.
    */
    $first = approveIt(orderForCohort());

    $running = Subscription::query()->where('order_id', $first->getKey())->firstOrFail();
    $runningEnd = CarbonImmutable::parse($running->effective_ends_on);

    approveIt(orderForCohort());

    $renewal = Subscription::query()
        ->where('student_user_id', $this->student->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    expect($renewal->getKey())->not->toBe($running->getKey())
        ->and(CarbonImmutable::parse($renewal->starts_on)->toDateString())
        ->toBe($runningEnd->addDay()->toDateString())
        ->and(CarbonImmutable::parse($renewal->ends_on)->toDateString())
        // Inclusive end: thirty days starting the day after is +29.
        ->toBe($runningEnd->addDay()->addDays(29)->toDateString());
});

it('extends a renewal across a plan the teacher repriced', function (): void {
    /*
    | ٠٢٧ · T075 — السقفُ الذي كُتِبَ حينَ شُحِنَ التمديد، ثمّ أُغلِق.
    |
    | ⛔ `DecidePlanChange` لا يُحرِّرُ الباقةَ: يكتبُ صفّاً جديداً ويُحيلُ القديم،
    | فالتجديدُ يقعُ على `plan_id` آخرَ ويجدُ البحثُ لا شيءَ — فيعودُ البدءُ من
    | اليومِ ويُبتَلَعُ ما تبقّى. **وإعادةُ التسعيرِ أكثرُ ما يفعلُه مدرّس**، فهذا
    | ليسَ طرفاً نادراً بل الطريقَ المعتاد.
    |
    | ⚠️ والتجهيزةُ تكتبُ `approved_plan_id` مباشرةً لأنّ المقصودَ هو الرابطُ لا
    | مسارُ الموافقة: مشيُ `DecidePlanChange` كاملاً هنا يقيسُ ذلكَ الفعلَ ولا
    | يقيسُ أنّ التمديدَ يعبُرُه — وهو سؤالُ هذا الملفّ.
    */
    $first = approveIt(orderForCohort());
    $running = Subscription::query()->where('order_id', $first->getKey())->firstOrFail();
    $runningEnd = CarbonImmutable::parse($running->effective_ends_on);

    $repriced = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'الشهري — جماعي (سعر جديد)',
        'duration_days' => 30,
        'price_minor' => 60_000,
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => $this->course->uuid,
    ]);

    PlanChangeRequest::query()->forceCreate([
        'workspace_id' => $this->workspace->getKey(),
        'uuid' => (string) Str::uuid(),
        'plan_id' => $this->plan->getKey(),
        'approved_plan_id' => $repriced->getKey(),
        'status' => 'approved',
        'requested_by' => $this->teacher->getKey(),
        'requested_at' => now(),
        'current_session_type' => 'group',
        'current_coverage_type' => 'course',
        'current_coverage_uuid' => (string) $this->course->uuid,
        'current_duration_days' => 30,
        'requested_session_type' => 'group',
        'requested_coverage_type' => 'course',
        'requested_coverage_uuid' => (string) $this->course->uuid,
        'requested_duration_days' => 30,
        'current_price_minor' => 45_000,
        'requested_price_minor' => 60_000,
    ]);

    $this->plan->forceFill(['is_active' => false])->save();
    $this->plan = $repriced;

    approveIt(orderForCohort());

    $renewal = Subscription::query()
        ->where('plan_id', $repriced->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    expect(CarbonImmutable::parse($renewal->starts_on)->toDateString())
        ->toBe($runningEnd->addDay()->toDateString());
});

/*
| The subscription's day is the PLATFORM's day, stored as UTC instants.
|
| ⛔ `CarbonImmutable::today()` is UTC, three hours behind Doha. Approved at 00:30
| Doha time on the 15th (21:30Z on the 14th), the subscription was dated from the
| 14th — a paid day that had already ended — and the enrolment's `expires_at`
| closed at 03:00 Doha the morning AFTER the last day.
*/
it('dates a subscription approved after local midnight from the platform day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:30:00', 'UTC'));

    $order = approveIt(orderForCohort());

    $subscription = Subscription::query()->where('order_id', $order->getKey())->firstOrFail();

    expect(CarbonImmutable::parse($subscription->starts_on)->toDateString())->toBe('2026-10-15')
        // Thirty days INCLUSIVE: the 15th of October through the 13th of
        // November. It was the 14th — a 31st day nobody paid for.
        ->and(CarbonImmutable::parse($subscription->ends_on)->toDateString())->toBe('2026-11-13');

    $enrollment = Enrollment::query()->withoutWorkspaceScope()
        ->where('student_user_id', $this->student->getKey())
        ->where('course_id', $this->course->getKey())
        ->firstOrFail();

    // The last instant of 2026-11-13 in Doha is 20:59:59 UTC — not 23:59:59.
    expect(CarbonImmutable::parse($enrollment->expires_at)->utc()->toDateTimeString())
        ->toBe('2026-11-13 20:59:59');
});

it('expires a subscription when its last day has ended in Doha, not three hours later', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));

    $order = approveIt(orderForCohort());
    $subscription = Subscription::query()->where('order_id', $order->getKey())->firstOrFail();
    $lastDay = CarbonImmutable::parse($subscription->effective_ends_on)->toDateString();

    // 22:00Z on the last day is 01:00 the next morning in Doha: the day is over.
    $this->travelTo(CarbonImmutable::parse($lastDay.' 22:00:00', 'UTC'));

    (new ExpireSubscriptionsJob)->handle(app(DispatchNotification::class));

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('claims the group seats of the extension days when a later freeze moves the end', function (): void {
    /*
    | Spec 027's quickstart: «تُحجَزُ حصصُ أيّامِ التمديد كذلك». At approval the
    | seat claim runs to the end the subscription had THEN; a freeze declared
    | afterwards moved the end and nothing claimed the week it added.
    */
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));

    $order = approveIt(orderForCohort());
    $subscription = Subscription::query()->where('order_id', $order->getKey())->firstOrFail();

    Queue::fake([ClaimSubscriptionSeatsJob::class]);

    FreezePeriod::create([
        'workspace_id' => $this->workspace->getKey(),
        'student_user_id' => null,
        'starts_on' => CarbonImmutable::parse('2026-10-20'),
        'ends_on' => CarbonImmutable::parse('2026-10-26'),
        'reason' => 'إجازة',
        'created_by' => $this->teacher->getKey(),
    ]);

    $end = $subscription->refresh()->effective_ends_on->toDateString();

    expect($end)->toBe('2026-11-20');

    $windowEnd = new ReflectionProperty(ClaimSubscriptionSeatsJob::class, 'windowEnd');

    Queue::assertPushed(ClaimSubscriptionSeatsJob::class, fn (ClaimSubscriptionSeatsJob $job): bool => $windowEnd->getValue($job) === $end);
});
