<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Support\EloquentEnrollmentDirectory;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SubscriptionDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **سؤالُ التسجيلِ وسؤالُ الاشتراكِ يُسألُ كلٌّ منهما مرّةً في الطلبِ الواحد.**
|
| كانا يُسألانِ مرّتَين، وكلُّ سائلٍ على حقٍّ في سؤالِه:
|
|  • **`enrollments`**: `ClassSessionPolicy::view()` يسألُ «هل هو مسجَّلٌ عندَ
|    هذا المدرّس؟» عندَ الباب، ثمّ `BookingEligibility::refusalReason()` يسألُ
|    السؤالَ نفسَه بالتهجئةِ نفسِها — وهذا مكتوبٌ في تعليقِ السياسةِ على أنّه
|    مقصود. المقصودُ هو التهجئةُ الواحدة، لا القراءةُ المكرَّرة.
|
|  • **`subscriptions`**: `EloquentAccountStanding::refusalFor()` يسألُ
|    `coversCourse()` في صدرِ مشيتِه، ثمّ `EloquentSessionCreditHolds::place()`
|    يسألُها ثانيةً قبلَ أن يُجمِّدَ رصيداً.
|
| ⚠️ **والطالبُ هنا بلا مقعدٍ عن قصد**: `ClassSessionPolicy::view()` يسألُ
| `holdsSeat()` أوّلاً، فالطالبُ الذي يحملُ مقعداً لا يبلغُ سطرَ التسجيلِ أصلاً
| — وتركيبةٌ بمقعدٍ تقيسُ ذاكرةً لا تُطرَقُ وتمرُّ خضراءَ على بناءٍ بلا ذاكرة.
|
| **كيفَ يُمسَك**: احذفِ الذاكرةَ من `EloquentEnrollmentDirectory` أو من
| `SubscriptionEligibility` ⇒ يسقطُ الشقُّ المعنيُّ بـ«٢ بدلَ ١».
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addDay(),
        'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 5,
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    fundBooking($this->workspace, $this->student, $this->course);
});

/** كم مرّةً قُرِئَ هذا الجدولُ في هذا النداء. */
function tableReads(string $table, Closure $body): int
{
    $reads = 0;

    DB::listen(function ($query) use ($table, &$reads): void {
        if (str_contains($query->sql, 'from "'.$table.'"')) {
            $reads++;
        }
    });

    $body();

    return $reads;
}

it('asks the enrolment question once on the eligibility screen', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $url = "/api/v1/class-sessions/{$this->session->uuid}/eligibility";

    // تحميةٌ: الإعداداتُ التشغيليّةُ صفوفٌ تُحفَظُ بعدَ أوّلِ قراءة.
    $this->getJson($url)->assertOk();

    /*
    | ⚠️ **وهذا السطرُ هو ما يجعلُ القياسَ صادقاً، لا تنظيفاً.** مُشغِّلُ
    | الاختباراتِ يُعيدُ استعمالَ حاوٍ واحدٍ عبرَ نداءاتِ HTTP، بينما الإنتاجُ
    | لا يفعل: php-fpm يبدأُ عمليّةً جديدةً لكلِّ طلب، وOctane وعاملُ الطابورِ
    | ينسيانِ نُسَخَ `scoped` بينَ الطلبِ والطلب. فبغيرِ هذا السطرِ يقرأُ
    | النداءُ الثاني **صفراً** — ذاكرةَ النداءِ الأوّل — وهو رقمٌ لا يقعُ لأيِّ
    | طالبٍ حقيقيّ، ويُخفي أنَّ السؤالَ ما زالَ يُسألُ مرّتَينِ في الطلبِ الواحد.
    */
    $this->app->forgetScopedInstances();

    $reads = tableReads('enrollments', fn () => $this->getJson($url)->assertOk());

    expect($reads)->toBe(1);
});

it('asks the enrolment question once when a seat is booked', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $reads = tableReads('enrollments', fn () => $this
        ->postJson("/api/v1/class-sessions/{$this->session->uuid}/book")
        ->assertCreated());

    expect($reads)->toBe(1);
});

it('asks the subscription question once when a seat is booked', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $reads = tableReads('subscriptions', fn () => $this
        ->postJson("/api/v1/class-sessions/{$this->session->uuid}/book")
        ->assertCreated());

    expect($reads)->toBe(1);
});

/*
| ⚠️ **والضابطُ على المعنى، لا على العدد.** قراءةٌ واحدةٌ تُرضيها ذاكرةٌ لا
| تُبطَلُ أبداً إرضاءً تامّاً — وهي ذاكرةٌ تكذب. فهذانِ الشقّانِ يسألانِ ثمّ
| يكتبانِ ثمّ يسألانِ في عمرِ حاوٍ واحد.
|
| وهذا هو بالضبطِ سببُ رفضِ الذاكرةِ في `AccountStanding` (انظر
| `OneMoneyQuestionTest`): الكنسُ الليليُّ يمسكُ نسخةً واحدةً طوالَ دورتِه
| **ويُحرِّرُ رصيداً داخلَها**. فالفرقُ هنا أنَّ الكتابةَ تُبطِلُ الذاكرة.
*/
it('does not keep answering «not enrolled» after the enrolment is written', function (): void {
    $directory = app(EnrollmentDirectory::class);
    $newcomer = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    expect($directory->hasActiveEnrollmentInWorkspace($newcomer, (int) $this->workspace->getKey()))->toBeFalse();

    $this->createEnrollment($this->workspace, $this->course, $newcomer);

    expect($directory->hasActiveEnrollmentInWorkspace($newcomer, (int) $this->workspace->getKey()))->toBeTrue();
});

it('does not keep answering «not covered» after the subscription is written', function (): void {
    $subscriptions = app(SubscriptionEligibility::class);
    $courseId = (int) $this->course->getKey();
    $studentId = (int) $this->student->getKey();

    expect($subscriptions->coversCourse($studentId, $courseId))->toBeFalse();

    $plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'session_type' => ClassSessionType::Group,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    Subscription::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'plan_id' => $plan->getKey(),
        'student_user_id' => $studentId,
        'status' => SubscriptionStatus::Active,
        'starts_on' => CarbonImmutable::now()->subDay(),
        'ends_on' => CarbonImmutable::now()->addDays(30),
        'effective_ends_on' => CarbonImmutable::now()->addDays(30),
    ]);

    expect($subscriptions->coversCourse($studentId, $courseId))->toBeTrue();
});

/*
| ⚠️ **الحجّةُ الحاويّةُ تُقاسُ ولا تُفترَض.** `coversCourse()` ليسَت على
| `SubscriptionDirectory`، فبابانِ يحقنانِ الصنفَ نفسَه مباشرةً وثالثٌ يحقنُ
| الواجهة. لو بقيَ الصنفُ بلا رَبْطٍ لبنى الحاوي لكلِّ واحدٍ نسخةً خاصّةً به
| ولما التقَت ذاكرةٌ بذاكرة — والعدُّ فوقَ كانَ سيقولَ «٢» بلا سببٍ ظاهر.
*/
it('hands both doors the same subscription reader', function (): void {
    expect(app(SubscriptionEligibility::class))
        ->toBe(app(SubscriptionDirectory::class));
});

it('hands both doors the same enrolment reader', function (): void {
    expect(app(EloquentEnrollmentDirectory::class))
        ->toBe(app(EnrollmentDirectory::class));
});
