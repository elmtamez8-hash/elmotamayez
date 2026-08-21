<?php

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
 * A fresh session for testing the live room, bookings and all.
 *
 * ⚠️ THROUGH THE ACTIONS, NOT RAW INSERTS. `ScheduleClassSession` owns the overlap
 * check, the freeze-period check and the seat rules; `BookSeat` owns the credit
 * floor. A hand-written INSERT produces rows the product would never have made,
 * and the first thing a test on them proves is that a broken fixture behaves oddly.
 *
 * ⚠️ AND INSIDE `forWorkspace()`, never `WorkspaceContext::set()` — the context is
 * an application-wide singleton that caches its resolution, so setting it from a
 * console command leaks that workspace into whatever the process handles next.
 */
app(WorkspaceContext::class)->forWorkspace(Workspace::query()->findOrFail(1), function (): void {
    $teacher = TeacherProfile::query()->findOrFail(1);
    $course = Course::query()->where('status', 'published')->firstOrFail();
    $host = User::query()->findOrFail((int) $teacher->user_id);

    // The join window is 15 minutes, so a start ten minutes out makes the room
    // openable immediately.
    $startsAt = CarbonImmutable::now()->addMinutes(10);

    $session = app(ScheduleClassSession::class)->handle(
        new ScheduleSessionData(
            teacherProfileId: (int) $teacher->getKey(),
            title: 'حصة تجريبية — بثّ مباشر ('.CarbonImmutable::now()->format('H:i').')',
            type: ClassSessionType::Group,
            startsAt: $startsAt,
            durationMinutes: 120,
            seatsTotal: 5,
            courseId: (int) $course->getKey(),
            subjectId: $course->subject_id === null ? null : (int) $course->subject_id,
        ),
        $host,
    );

    // Students actively enrolled in this very course — booking is course-bound
    // since spec 006, so a student from another course would be refused for a
    // reason that has nothing to do with what is being tested.
    $studentIds = \App\Modules\Learning\Models\Enrollment::query()
        ->where('course_id', $course->getKey())
        ->where('status', 'active')
        ->limit(2)
        ->pluck('student_user_id')
        ->all();

    $accounts = app(CreditAccounts::class);
    $adjust = app(AdjustCredits::class);
    $book = app(BookSeat::class);

    foreach ($studentIds as $studentId) {
        $student = User::query()->findOrFail($studentId);
        $balance = $accounts->balanceFor($student, $course);

        // The credit floor is spec 006 working correctly, not an obstacle to
        // route around — so the seats are paid for through the ledger.
        $adjust->handle(
            $balance,
            CreditTransactionType::Bonus,
            3,
            'رصيد تجريبي لاختبار البثّ المباشر',
            'test-broadcast-'.$studentId.'-'.$session->uuid,
        );

        try {
            $book->handle($session, $student);
            echo 'booked: ', $student->email, PHP_EOL;
        } catch (Throwable $e) {
            echo 'refused: ', $student->email, ' — ', $e->getMessage(), PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo 'uuid       = ', $session->uuid, PHP_EOL;
    echo 'starts_at  = ', $session->starts_at, PHP_EOL;
    echo 'ends_at    = ', $session->fresh()->ends_at, PHP_EOL;
    echo 'room opens = ', $startsAt->subMinutes(15)->format('H:i'), PHP_EOL;
    echo 'host       = ', $host->email, PHP_EOL;
    echo 'seats      = ', $session->fresh()->seats_taken, '/', $session->seats_total, PHP_EOL;
    echo 'url        = /manage/sessions/', $session->uuid, PHP_EOL;
});
