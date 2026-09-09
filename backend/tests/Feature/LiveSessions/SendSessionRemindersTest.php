<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\SendSessionRemindersJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/*
| Spec 052 · «تذكير موعد» — the sender the type never had.
|
| ⚠️ السؤالُ الأوّلُ ليسَ «هل وصلَ تذكير» بل «إلى مَن». حاملُ المقعدِ لا المسجَّلُ في
| الكورس: مجموعةُ السبتِ ليست طلّابَ حصّةِ الأحد، وكلُّ ٠٢١ قائمٌ على هذا الفرق.
|
| ⚠️ والحالةُ الثانيةُ هي التي تسقطُ وحدَها إن حُذِفَ `whereNull('reminded_at')` من
| الادّعاء — «تبدأُ بعدَ ساعة» صحيحةٌ في كلِّ مرورٍ تالٍ، فالعلامةُ — لا النافذةُ —
| هي ما يجعلُ المكنسةَ تتقارب.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->cohort = Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

function reminderStudent(string $name = 'طالب'): User
{
    $test = test();

    // `last_workspace_id` left null: a student is a member of no workspace, and a
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

function reminderSession(
    CarbonImmutable $startsAt,
    ClassSessionStatus $status = ClassSessionStatus::Scheduled,
): ClassSession {
    $test = test();

    return app(WorkspaceContext::class)->forWorkspace(
        $test->workspace,
        fn (): ClassSession => ClassSession::factory()->create([
            'workspace_id' => $test->workspace->getKey(),
            'course_id' => $test->course->getKey(),
            'cohort_id' => $test->cohort->getKey(),
            'type' => ClassSessionType::Group,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'seats_total' => 8,
            'seats_taken' => 0,
        ]),
    );
}

function reminderSeatIn(ClassSession $session, User $student): void
{
    app(BookSeat::class)->handle($session, $student);
}

/**
 * One pass of the sweep, through the JOB rather than a hand-rolled query.
 *
 * The mark, the window and the audience are exactly what is being measured, and
 * a helper that reproduced any of them would be measuring itself.
 */
function runReminderSweep(): void
{
    app(SendSessionRemindersJob::class)->handle(
        app(SessionSettings::class),
        app(SessionAttendanceDirectory::class),
        app(DispatchNotification::class),
    );
}

/** @return EloquentCollection<int, Notification> */
function remindersSent(): EloquentCollection
{
    return Notification::query()
        ->where('type', NotificationType::AppointmentReminder->value)
        ->get();
}

it('reminds whoever holds a seat, and nobody else', function (): void {
    $seated = reminderStudent('حامل المقعد');
    // Enrolled in the same course and in the same group — and NOT booked into
    // this hour. The audience is the seat, never the enrolment.
    $unseated = reminderStudent('بلا مقعد');

    $session = reminderSession(CarbonImmutable::now()->addMinutes(30));
    reminderSeatIn($session, $seated);

    runReminderSweep();

    $recipients = remindersSent()->pluck('recipient_user_id')->all();

    expect($recipients)->toContain($seated->getKey())
        ->and($recipients)->not->toContain($unseated->getKey());
});

it('sends once, however many times the sweep runs', function (): void {
    $student = reminderStudent();
    $session = reminderSession(CarbonImmutable::now()->addMinutes(30));
    reminderSeatIn($session, $student);

    runReminderSweep();
    runReminderSweep();
    runReminderSweep();

    /*
    | ⚠️ **واحدٌ لا ثلاثة.** الشرطُ الزمنيُّ «تبدأُ بعدَ ساعة» يبقى صادقاً في كلِّ
    | مرورٍ تالٍ، فالعلامةُ — لا النافذةُ — هي ما يجعلُ المكنسةَ تتقارب. قِيسَ
    | بالحذف: بإسقاطِ `reminded_at` من الشرطَين معاً صارت النتيجةُ **ثلاثة**.
    |
    | ⚠️ ولا تفصلُ هذه الحالةُ بينَ الشرطَين، لأنّ كلَّ واحدٍ منهما وحدَه يكفي
    | لمرورٍ متتابع: الاستعلامُ الخارجيُّ لا يختارُ الصفَّ أصلاً، والـ UPDATE
    | المشروطُ يوافقُ صفراً فيعودُ. الثاني موجودٌ لعاملَينِ متزامنَينِ قرأا الصفَّ
    | قبلَ أن يختمَه أحدُهما، وهو ما لا يراهُ اختبارٌ متتابعٌ بحال — القاعدةُ نفسُها
    | التي كُتِبَت عن «افتحِ الغرفةَ مرّتَين»، حيثُ المرّةُ الثانيةُ تعودُ من سطرٍ
    | أعلى ولا تبلغُ الادّعاءَ قط.
    */
    expect(remindersSent())->toHaveCount(1)
        ->and($session->refresh()->reminded_at)->not->toBeNull();
});

it('leaves a lesson beyond the lead time alone', function (): void {
    $student = reminderStudent();
    // The default lead is 60 minutes; this one is a day out.
    $session = reminderSession(CarbonImmutable::now()->addDay());
    reminderSeatIn($session, $student);

    runReminderSweep();

    expect(remindersSent())->toHaveCount(0)
        ->and($session->refresh()->reminded_at)->toBeNull();
});

it('leaves a lesson that has already started alone', function (): void {
    $student = reminderStudent();
    $session = reminderSession(CarbonImmutable::now()->addMinutes(30));
    reminderSeatIn($session, $student);

    /*
    | The sweep has been down and comes back after the hour began. A reminder for
    | a lesson already under way is worse than none — it is read as «it is
    | starting now» — which is what the LOWER bound exists for.
    */
    $session->forceFill(['starts_at' => CarbonImmutable::now()->subMinutes(5)])->save();

    runReminderSweep();

    expect(remindersSent())->toHaveCount(0);
});

it('leaves a cancelled lesson alone', function (): void {
    $student = reminderStudent();
    $session = reminderSession(CarbonImmutable::now()->addMinutes(30));
    reminderSeatIn($session, $student);

    $session->forceFill(['status' => ClassSessionStatus::Cancelled])->save();

    runReminderSweep();

    expect(remindersSent())->toHaveCount(0);
});
