<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Events\PrivateSessionRequested;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «أريد حصّة خاصّة في هذا الكورس، الثلاثاء ٦م».
 *
 * ⚠️ NOTHING IS CREATED AND NOTHING IS CHARGED (FR-017). That is not an
 * optimisation — it is what makes a refusal free for both sides, and therefore
 * what makes «موافقة إنسان» a real decision rather than a formality after the
 * money has already moved.
 *
 * ⚠️ AND IT IS ALSO WHY THE MONEY GUARD IS ASKED HERE. FR-025 puts the refusal
 * at SUBMISSION: a student blocked for an unpaid balance who is allowed to
 * submit spends three days waiting for a teacher to press a button that will
 * then refuse them, and neither of them ever learns why. The question asked is
 * `refusalReason()` — enrolment, freeze, withholding — and deliberately NOT
 * `openingRefusal()`: the homework gate governs the NEXT group lesson on a
 * course path, and a private hour is what a student asks for precisely because
 * they are behind.
 */
class RequestPrivateSession extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly BookingEligibility $eligibility,
        private readonly SessionSettings $settings,
    ) {}

    public function handle(Course $course, User $student, CarbonImmutable $startsAt): PrivateSessionRequest
    {
        // FR-015: the request is about a course the student BOUGHT. Asked
        // explicitly rather than left to a global scope — the student is a
        // member of no workspace, so `WorkspaceScope` adds no condition for them
        // and there is no implicit guard on this path at all.
        if (! $this->enrollments->hasActiveEnrollment($student, (int) $course->getKey())) {
            throw new DomainException('لست مسجّلاً في هذا الكورس.');
        }

        if ($startsAt->isPast()) {
            throw new DomainException('لا يمكن طلب موعد قد مضى.');
        }

        $teacherProfileId = $this->teacherProfileId($course);
        $minutes = $this->durationOf($course);

        // FR-016ب: the WHOLE duration, not its first minute. A start inside a
        // window and an end outside it books the teacher time they never
        // declared — and reads as accepted until they open their calendar.
        if (! $this->withinDeclaredAvailability($teacherProfileId, $startsAt, $minutes)) {
            throw new DomainException('هذا الوقت خارج مواعيد المدرّس المعلَنة.');
        }

        $this->assertEligible($course, $student, $startsAt);

        return $this->insertWithinLimit($course, $student, $teacherProfileId, $startsAt, $minutes);
    }

    /**
     * FR-025, asked through the one binding definition of an eligible student.
     *
     * The session it is asked about does not exist yet and must not — so it is
     * asked about an UNSAVED one carrying the three fields the question reads.
     * `StartConversation` authorises an unsaved `Conversation` for the same
     * reason: a second spelling of «may this student book» would answer
     * differently from the door the acceptance goes through.
     */
    private function assertEligible(Course $course, User $student, CarbonImmutable $startsAt): void
    {
        $probe = new ClassSession([
            'workspace_id' => $course->workspace_id,
            'course_id' => $course->getKey(),
            'starts_at' => $startsAt,
        ]);

        $refusal = $this->eligibility->refusalReason($probe, $student);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }
    }

    private function teacherProfileId(Course $course): int
    {
        $id = $course->teacher_profile_id;

        if ($id === null) {
            throw new DomainException('هذا الكورس ليس له مدرّس يستقبل الطلبات.');
        }

        return (int) $id;
    }

    /** FR-016أ — declared by the teacher on the course, never chosen by the student. */
    private function durationOf(Course $course): int
    {
        return $course->private_session_minutes ?? 60;
    }

    private function withinDeclaredAvailability(int $teacherProfileId, CarbonImmutable $startsAt, int $minutes): bool
    {
        $endsAt = $startsAt->addMinutes($minutes);

        /*
        | A declared window is a weekly one-day shape, so nothing crossing
        | midnight can lie inside one. Comparing `end_time` against the end's
        | clock time WITHOUT this would match a window on the same weekday of
        | the FOLLOWING week — a lesson at 23:30 «inside» a Tuesday morning slot.
        */
        if ($endsAt->format('w') !== $startsAt->format('w')) {
            return false;
        }

        return AvailabilitySlot::query()
            // The slot belongs to the teacher's workspace and the asker is a
            // student who belongs to none.
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacherProfileId)
            ->where('day_of_week', (int) $startsAt->format('w'))
            ->where('start_time', '<=', $startsAt->format('H:i:s'))
            ->where('end_time', '>=', $endsAt->format('H:i:s'))
            ->exists();
    }

    /**
     * The write, the duplicate guard and the ceiling — in one statement.
     *
     * ⚠️ `count()` THEN `insert()` IS THE DEFINITION OF THE RACE, and a request
     * is the cheapest row in the product to produce: it holds no seat and moves
     * no credit, so a student with a script fills a teacher's whole week in a
     * minute. The count is a correlated subquery in the INSERT's own WHERE, so
     * the number is read and the row written under one lock.
     *
     * ⚠️ AND NO MODEL IS BOOTED BY A RAW INSERT, so `HasUuid` never fires. Left
     * to the database that is a NOT NULL violation MySQL downgrades to a warning
     * — storing an empty string, after which every later request on the platform
     * collides with that one row on `unique(uuid)` and is silently swallowed.
     * `uuid` and both timestamps are named explicitly for that reason.
     *
     * ⚠️ AND THE STATEMENT HAS TWO DISTINCT FAILURES. Zero rows affected is the
     * ceiling; a unique violation is the same moment asked for twice. One
     * sentence for both would send a student to cancel a request they do not
     * have.
     */
    private function insertWithinLimit(
        Course $course,
        User $student,
        int $teacherProfileId,
        CarbonImmutable $startsAt,
        int $minutes,
    ): PrivateSessionRequest {
        $uuid = (string) Str::uuid();
        $limit = $this->settings->privateRequestMaxPending();
        $now = now();

        try {
            $written = DB::affectingStatement(
                'INSERT INTO private_session_requests
                    (workspace_id, uuid, course_id, student_user_id, teacher_profile_id,
                     starts_at, duration_minutes, status, expires_at, pending_slot,
                     created_at, updated_at)
                 SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?
                   FROM (SELECT 1) AS guard
                  WHERE (SELECT COUNT(*) FROM private_session_requests
                          WHERE student_user_id = ?
                            AND teacher_profile_id = ?
                            AND status = ?) < ?',
                [
                    $course->workspace_id, $uuid, $course->getKey(), $student->getKey(), $teacherProfileId,
                    $startsAt->utc()->format('Y-m-d H:i:s'), $minutes, PrivateSessionRequest::PENDING,
                    $now->copy()->addHours($this->settings->privateRequestTtlHours())->format('Y-m-d H:i:s'),
                    $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
                    $student->getKey(), $teacherProfileId, PrivateSessionRequest::PENDING, $limit,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('لديك طلب قائم على هذا الموعد بالفعل.');
        }

        if ($written === 0) {
            throw new DomainException("لديك {$limit} طلبات تنتظر الردّ عند هذا المدرّس. انتظر الردّ أو اسحب أحدها.");
        }

        $request = PrivateSessionRequest::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->first();

        if ($request === null) {
            // Zero rows read back after a statement that reported one written is
            // not a duplicate and not a ceiling — it is a fault, and returning
            // null here would report success for a request nobody holds.
            throw new DomainException('تعذّر تسجيل الطلب. حاول مرة أخرى.');
        }

        PrivateSessionRequested::dispatch($request);

        return $request;
    }
}
