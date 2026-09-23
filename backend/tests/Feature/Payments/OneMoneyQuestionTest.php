<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\SubscriptionEligibility;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AccountStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **سؤالُ المالِ يُسأَلُ مرّةً واحدةً، والرفضُ كانَ يسألُه مرّتَين.**
|
| الحكمُ («هل هو محجوب؟») والرقمُ («كم حصّةً يحتاج؟») مشيةٌ واحدة: الحسابُ ثمّ
| صفوفُ الرصيدِ ثمّ مساحةُ العملِ ونافذةُ امتحاناتِها. و٠٠٦ · FR-032 يوجبُ أن
| تحملَ جملةُ الرفضِ الرقمَ — فكلُّ رافضٍ في المنتَجِ يسألُ الاثنَينِ معاً، وكانَ
| كلُّ سؤالٍ يمشي المشيةَ من أوّلِها.
|
| **قِيسَ**: `/eligibility` لطالبٍ بلا رصيدٍ كانَ **١٦ استعلاماً وصارَ ١٢** —
| أربعةُ صفوفٍ كانت تُقرَأُ مرّتَين: الحسابُ والرصيدُ ونافذةُ الامتحاناتِ
| ومساحةُ العمل.
|
| ⚠️ **والـ`enrollments` الذي يبقى مقروءاً مرّتَينِ ليسَ من هذا**، وقد عُرِفَ
| سببُهُ وتُرِكَ عن قصد: `ClassSessionPolicy::view()` يسألُ عندَ الباب، ثمّ
| `BookingEligibility::refusalReason()` يسألُ بالتهجئةِ نفسِها خلفَه — وكلاهما
| على حقٍّ في سؤالِه. لماذا لم يُغلَقْ: انظرِ الفقرةَ التالية.
|
| ⚠️ **ولم تُحَلَّ بذاكرةٍ مؤقّتة**، وهذا الرفضُ مقصود: ذلكَ الكنسُ يمسكُ نسخةً
| واحدةً من الصنفِ طوالَ دورتِه **ويُحرِّرُ رصيداً داخلَها**، فحكمٌ محفوظٌ يعيشُ
| أطولَ من الرصيدِ الذي يصفُه.
|
| ⛔ **وهذا الرفضُ اختُبِرَ على جارَتَيهِ في ٢٠٢٦-٠٩-١٦، فصحَّ.** بُنِيَت ذاكرةٌ
| بعمرِ الطلبِ لـ`EloquentEnrollmentDirectory` و`SubscriptionEligibility`
| تُبطِلُها خطّافاتُ `saved`/`deleted` — وقِيسَ ٢ ⇒ ١ في المسارَينِ — ثمّ
| رُدَّت في اليومِ نفسِه: **التحديثُ بالجملةِ لا يُحضِرُ نماذجَ ولا يُطلِقُ
| أحداثاً** (قاعدةُ `LedgerEntry` من بابٍ جديد)، فأمسكَها
| `ArchiveAfterEnrollmentEndsTest`: طالبٌ **انتهى تسجيلُه** ظلَّ يكتبُ في محادثةِ
| المدرّس — ٢٠١ حيثُ يوجَبُ ٤٠٣. واتّجاهُ الفشلِ هو الحاسم: استحقاقٌ محفوظٌ
| **يَفتَحُ** ولا يُغلِق، بلا سطرٍ في أيِّ سجلّ. وإحكامُه يحتاجُ مُنصِتاً على
| كلِّ عبارةِ SQL في التطبيقِ ثمناً لاستعلامٍ واحد.
|
| **كيفَ يمسك**: أعِدْ `BookingEligibility::withholdingRefusal()` إلى
| `isWithheld()` ثمّ `creditsNeededFor()` ⇒ يسقطُ الشقُّ الأوّلُ بـ«٢ بدلَ ١».
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

    // الرفضُ هو موضعُ القياس: الطريقُ السعيدُ يسألُ الحكمَ ولا يبلغُ الرقمَ أصلاً.
    CreditBalance::query()->withoutWorkspaceScope()
        ->where('course_id', $this->course->getKey())
        ->where('student_user_id', $this->student->getKey())
        ->update(['remaining_credits' => 0, 'held_credits' => 0, 'credit_limit_credits' => 0]);
});

/** كم مرّةً قُرِئَت صفوفُ الرصيدِ في هذا النداء. */
function balanceReads(Closure $body): int
{
    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "credit_balances" where "student_credit_account_id"')) {
            $reads++;
        }
    });

    $body();

    return $reads;
}

it('reads the balance once to refuse a booking', function (): void {
    Sanctum::actingAs($this->student);
    $this->asGuest();

    $url = "/api/v1/class-sessions/{$this->session->uuid}/eligibility";

    // تحميةٌ: الإعداداتُ التشغيليّةُ صفوفٌ تُحفَظُ بعدَ أوّلِ قراءة.
    $this->getJson($url)->assertOk();

    $reads = balanceReads(fn () => $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.open', false)
        ->assertJsonPath('data.booking_refusal', fn (?string $reason): bool => $reason !== null
            && str_contains($reason, 'رصيدك')));

    expect($reads)->toBe(1);
});

/*
| ⚠️ **وهذا شقُّ العقدِ نفسِه، لا شقُّ بابٍ**: يُثبِتُ أنّ `refusalFor()` تردُّ
| النصفَينِ من قراءةٍ واحدة. وبابُ المِلفِّ عالي القيمةِ — الرافضُ الثاني الذي
| كانَ يسألُ النصفَينِ — مقيسٌ في موضعِه حيثُ تركيبتُه:
| `HighValueAssetTest` · «يقرأُ الرصيدَ مرّةً واحدةً ليرفضَ ملفّاً».
*/
it('answers both halves from one read', function (): void {
    $standing = app(AccountStanding::class);

    $reads = balanceReads(function () use ($standing): void {
        $verdict = $standing->refusalFor($this->student, (int) $this->course->getKey());

        expect($verdict['withheld'])->toBeTrue()
            ->and($verdict['credits_needed'])->toBeGreaterThan(0);
    });

    expect($reads)->toBe(1);
});

/*
| ⚠️ **والضابطُ على المعنى، لا على العدد.** قراءةٌ واحدةٌ تُرضيها نسخةٌ تردُّ
| «غيرُ محجوب» دائماً إرضاءً تامّاً — فالشقّانِ فوقَ يوكِّدانِ الحكمَ والرقمَ معاً،
| وهذا الشقُّ يُثبِتُ أنَّ الطريقَ السعيدَ ما زالَ سعيداً.
*/
it('still lets a funded student through, and asks for nothing', function (): void {
    // ⚠️ مصدرٌ آخرُ لا `fundBooking` ثانيةً: مفتاحُ الدفترِ الفريدُ
    // (رصيد، نوع، مصدر، معرِّف)، فنداءٌ ثانٍ بالمصدرِ نفسِه يُتجاهَلُ في صمت.
    grantCredits(
        billingBalance($this->workspace, $this->student, $this->course),
        5,
        'fixture-top-up',
    );

    $verdict = app(AccountStanding::class)->refusalFor($this->student, (int) $this->course->getKey());

    expect($verdict['withheld'])->toBeFalse()
        ->and($verdict['credits_needed'])->toBe(0);
});

/*
| ⚠️ **والمشترِكُ يحتاجُ صفرَ حصص، وهو ما كانَ `creditsNeededFor()` يُخطئُه.**
| لم يكنْ يسألُ عن الاشتراكِ أصلاً، فكانَ يقولُ لمشترِكٍ يحملُ صفَّ رصيدٍ قديماً
| «اشترِ كذا حصّة» عن كورسٍ اشتراكُه يدفعُ ثمنَه.
*/
it('quotes no credits to a subscriber', function (): void {
    $covered = mock(SubscriptionEligibility::class);
    $covered->shouldReceive('coversCourse')->andReturn(true);
    $covered->shouldReceive('coveredCourseIds')->andReturn([]);
    app()->instance(SubscriptionEligibility::class, $covered);

    $standing = app(AccountStanding::class);

    expect($standing->isWithheld($this->student, (int) $this->course->getKey()))->toBeFalse()
        ->and($standing->creditsNeededFor($this->student, (int) $this->course->getKey()))->toBe(0);
});
