<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| Spec 052 — عضوُ المجموعةِ يُحجَزُ له تلقائيّاً، لا المشتركُ وحدَه.
|
| ⚠️ الآلةُ كانت موجودةً كلُّها وكان الاشتراكُ بوّابةً عليها. `ClaimSubscriptionSeats`
| و`BookSubscribersOnScheduled` و`ReleaseSeatsOnTransfer` تكتبُ المقعدَ منذُ ٠٢٧ —
| لكنَّ `subscriberIdsAmong()` كان يُسقِطُ كلَّ مَن يدفعُ بالرصيد، وهم أكثرُ الطلّاب.
| فالمجموعةُ تُنشَأُ ويُنشَرُ فصلٌ كاملٌ ولا يُحجَزُ لأحدٍ شيء، ويبحثُ كلُّ طالبٍ عن
| كلِّ حصّةٍ ويضغطُ «احجز» بيدِه — وهو ما ظهرَ أخيراً كحصّةٍ حيّةٍ بلا مقعدٍ واحد.
|
| ⚠️ والحالةُ الثانيةُ هي التي تسقطُ بلا تغييرِ الحدَث: `CohortMembershipOpened` لم
| يكن يُطلَقُ عندَ أوّلِ انضمام، والحالةُ العاديّةُ هي مدرّسٌ ينشرُ فصلاً يومَ الأحدِ
| ويلتحقُ الطلّابُ خلالَ الأسبوع.
|
| ⚠️ والثالثةُ هي البابُ نفسُه: `BookSeat::handle()` لا `claimGrantedSeat()`. مقعدُ
| المشتركِ مدفوعٌ سلفاً فيُعفى من بوّابةِ ٠٤١؛ وعضوُ المجموعةِ لم يشترِ هذه الحصّةَ
| بعد — الرصيدُ يُخصَمُ عندَ التسليم — فالسؤالُ هو سؤالُ زرِّه هو.
*/

beforeEach(function (): void {
    /*
    | ⚠️ بلا هذا يُختَمُ `billable_seats` لحظةَ الجدولة، فيردُّ كلُّ ادّعاءٍ بعدَها
    | «أُغلق حساب مقاعد هذه الحصة» — لأنّ `->delay()` على وصلةِ `sync` يُنفَّذُ فوراً،
    | فـ`FreezeBillableSeatsJob` يقعُ داخلَ الجدولةِ نفسِها بدلَ موعدِ الإلغاء. قِيسَ:
    | الحالةُ الثانيةُ كانت تسقطُ بصفرِ مقاعدَ وسببُها هذا لا الحدَث.
    */
    fakeSessionTimeline();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill([
        'status' => 'published',
        'teacher_profile_id' => $this->profile->getKey(),
    ])->save();

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
        'status' => Cohort::OPEN,
    ]);
});

/**
 * A student who pays by CREDIT and holds no subscription at all — which is most
 * of them, and precisely the person the old gate dropped.
 */
function creditPayingStudent(string $name = 'طالب'): User
{
    $test = test();

    // `last_workspace_id` left null: a student is a member of no workspace, so a
    // fixture that stamps it measures a person production never creates.
    $student = User::factory()->create(['first_name' => $name, 'last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $test->workspace->getKey(),
        'course_id' => $test->course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        'status' => EnrollmentStatus::Active,
        'enrolled_at' => now(),
    ]);

    fundBooking($test->workspace, $student, $test->course);

    return $student;
}

/** Puts the student in the group the way the product does — through the Action. */
function joinTheGroup(User $student): void
{
    app(JoinCohort::class)->handle(test()->cohort, $student);
}

function scheduleGroupSession(string $when = '+3 days', int $seatsTotal = 8): ClassSession
{
    $test = test();

    return app(ScheduleClassSession::class)->handle(new ScheduleSessionData(
        teacherProfileId: (int) $test->profile->getKey(),
        title: 'درس المجموعة',
        type: ClassSessionType::Group,
        startsAt: CarbonImmutable::parse($when),
        durationMinutes: 60,
        seatsTotal: $seatsTotal,
        courseId: (int) $test->course->getKey(),
        cohortId: (int) $test->cohort->getKey(),
    ), $test->owner);
}

function seatsHeldBy(User $student): int
{
    return SessionBooking::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $student->getKey())
        ->where('status', BookingStatus::Booked)
        ->count();
}

it('seats every member of the group when the teacher schedules a lesson', function (): void {
    $student = creditPayingStudent();
    joinTheGroup($student);

    $session = scheduleGroupSession();

    /*
    | ⚠️ لا اشتراكَ في هذه التركيبةِ بحال، وهذا هو الاختبارُ كلُّه. قبلَ ٠٥٢ كان
    | `subscriberIdsAmong()` يعودُ فارغاً فتنتهي دورةُ الحصّةِ عندَ السطرِ التالي،
    | ويخرجُ الطالبُ بلا مقعدٍ في حصّةِ مجموعتِه.
    */
    expect(seatsHeldBy($student))->toBe(1)
        ->and($session->refresh()->seats_taken)->toBe(1);
});

it('seats a student who joins AFTER the term was published', function (): void {
    $first = scheduleGroupSession('+3 days');
    $second = scheduleGroupSession('+10 days');

    $student = creditPayingStudent();

    /*
    | ⚠️ هذه هي الحالةُ التي تسقطُ إن عادَ `CohortMembershipOpened` إلى
    | `if ($existing !== null)`. أوّلُ انضمامٍ لم يكن يُطلِقُ الحدَثَ أصلاً — بحجّةِ
    | أنّ مَن لا مجموعةَ له لا مقاعدَ يتخلّى عنها — وهي حجّةٌ صحيحةٌ للنصفِ الذي
    | يُحرِّرُ وباطلةٌ للنصفِ الذي يحجز.
    */
    joinTheGroup($student);

    expect(seatsHeldBy($student))->toBe(2)
        ->and($first->refresh()->seats_taken)->toBe(1)
        ->and($second->refresh()->seats_taken)->toBe(1);
});

it('refuses the member the unlock gate refuses, and seats the classmate beside them', function (): void {
    $gated = creditPayingStudent('المتعثّر');
    $clear = creditPayingStudent('المنتظم');

    joinTheGroup($gated);
    joinTheGroup($clear);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($gated, $clear): void {
        UnlockRule::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'requires_attendance' => true,
            'requires_assignment' => false,
            'min_score_pct' => 0,
        ]);

        // «الحصّةُ السابقة» تعني السابقةَ في مجموعةِ هذا الطالب، فتحملُ المجموعةَ
        // نفسَها — بلا ذلك يقارنُ القفلُ بحصّةٍ لم يكن الطالبُ فيها أصلاً.
        $missed = ClassSession::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->profile->getKey(),
            'course_id' => $this->course->getKey(),
            'cohort_id' => $this->cohort->getKey(),
            'type' => ClassSessionType::Group,
            'status' => ClassSessionStatus::Completed,
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'seats_total' => 8,
            'seats_taken' => 0,
        ]);

        attendanceRow($this->workspace, $missed, $gated, AttendanceStatus::Absent);

        // ⚠️ ولزميلِه صفٌّ في السجلِّ نفسِه بحضور. لا يكفي أن نتركَه بلا صفّ: غيابُ
        // القيدِ ليسَ حضوراً، فيقفلُ عليه القفلُ نفسُه ويصيرُ الاختبارُ «لا أحدَ
        // يُحجَزُ له» — وهو صحيحٌ على بناءٍ لا يحجزُ لأحدٍ أصلاً.
        attendanceRow($this->workspace, $missed, $clear, AttendanceStatus::Present);
    });

    $session = scheduleGroupSession();

    /*
    | ⚠️ الرقمانِ معاً. «لم يُحجَز للمتعثّر» وحدَه يمرُّ على بناءٍ لا يحجزُ لأحد،
    | و«حُجِزَ للمنتظم» وحدَه يمرُّ على بناءٍ يستعملُ `claimGrantedSeat()` فيتجاوزُ
    | البوّابةَ للاثنَين. الفرقُ بينَهما هو ما يُثبِتُ البابَ.
    */
    expect(seatsHeldBy($gated))->toBe(0)
        ->and(seatsHeldBy($clear))->toBe(1)
        ->and($session->refresh()->seats_taken)->toBe(1);
});

it('leaves a seat the student already holds exactly as it was', function (): void {
    $student = creditPayingStudent();
    joinTheGroup($student);

    $session = scheduleGroupSession();

    expect($session->refresh()->seats_taken)->toBe(1);

    // The same student, moved back into the group they are already in, is what a
    // second pass looks like from the seat's side. `decide()` reads the Booked
    // row and returns `''` — nothing done, nothing reported, and above all no
    // second INSERT for the unique index to refuse.
    app(WorkspaceContext::class)->forWorkspace($this->workspace, function () use ($student): void {
        app(ClaimSubscriptionSeats::class)->forMemberInCohort(
            (int) $this->workspace->getKey(),
            $student,
            (int) $this->course->getKey(),
            (int) $this->cohort->getKey(),
        );
    });

    expect(seatsHeldBy($student))->toBe(1)
        ->and($session->refresh()->seats_taken)->toBe(1);
});
