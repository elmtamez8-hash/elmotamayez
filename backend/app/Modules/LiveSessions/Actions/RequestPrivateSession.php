<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Events\PrivateSessionRequested;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Modules\LiveSessions\Support\LeadTime;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\CountedNoun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;

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
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly BookingEligibility $eligibility,
        private readonly SessionSettings $settings,
        private readonly LeadTime $lead,
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

        // Too soon is not the past: an hour one minute away is one no teacher
        // can answer and prepare for. The course page offers nothing inside
        // this window, so reaching it means a hand-built request.
        $tooSoon = $this->lead->refusalFor($startsAt);

        if ($tooSoon !== null) {
            throw new DomainException($tooSoon);
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
     * asked about an UNSAVED one carrying the fields the question reads — the
     * TYPE among them since the money question asks the plan's room size.
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
            'type' => ClassSessionType::Individual,
        ]);

        // The course half too (owner decision 2026-09-25): the teacher's grant
        // goes through `BookSeat::claimGrantedSeat()`, which asks it — so a
        // request for a course the student is not enrolled in would otherwise be
        // accepted here and refused at the teacher's press.
        $refusal = $this->eligibility->bookingScopeRefusal($probe, $student)
            ?? $this->eligibility->refusalReason($probe, $student);

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
        | ⚠️ ASKED OF EACH SLOT ON ITS OWN CLOCK, NOT AS A UTC `WHERE`. A slot is
        | wall-clock time in the teacher's zone (2026-09-25), so «Tuesday 17:00
        | Cairo» is 14:00Z in October and 15:00Z in November — one SQL comparison
        | of clock strings cannot say both. The teacher's slots are a handful of
        | rows; `containsSpan()` converts the ask into each one's zone, and refuses
        | a lesson that crosses midnight on that clock.
        */
        return AvailabilitySlot::query()
            // The slot belongs to the teacher's workspace and the asker is a
            // student who belongs to none.
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacherProfileId)
            ->get()
            ->contains(fn (AvailabilitySlot $slot): bool => $slot->containsSpan($startsAt, $endsAt));
    }

    /**
     * The write, the duplicate guard and the ceiling — in one transaction,
     * serialised on the teacher's profile row.
     *
     * ⚠️ A BARE `count()` THEN `insert()` IS THE DEFINITION OF THE RACE, and a
     * request is the cheapest row in the product to produce: it holds no seat
     * and moves no credit, so a student with a script fills a teacher's whole
     * week in a minute.
     *
     * ⛔ UNTIL 2026-09-27 THE COUNT WAS A SUBQUERY IN AN `INSERT … SELECT`, AND
     * THIS DOCBLOCK SAID THAT READ THE NUMBER AND WROTE THE ROW «UNDER ONE
     * LOCK». IT DID NOT: there is no row to lock in a count of rows that do not
     * exist yet. On MySQL under REPEATABLE READ two requests could both read the
     * count below the ceiling, or deadlock on the subquery's gap locks — a 500.
     *
     * So the transaction's FIRST statement writes the scope's parent row — the
     * teacher's profile (every request to one teacher waits in turn; a handful a
     * minute at most). InnoDB holds that lock until commit, so a second request
     * waits there until the first has committed, and only then counts, with a
     * plain read whose snapshot opens AFTER the lock was granted. ⚠️ Nothing may
     * be read above the gate, and this may not run inside a caller's
     * transaction: either pins a snapshot from before the winner committed. A
     * deadlock is retried (`attempts: 3`) and then refused as «حاول مرة أخرى» —
     * not as the ceiling, and not as a duplicate.
     *
     * ⚠️ AND NO MODEL IS BOOTED BY A RAW INSERT, so `HasUuid` never fires. Left
     * to the database that is a NOT NULL violation MySQL downgrades to a warning
     * — storing an empty string, after which every later request on the platform
     * collides with that one row on `unique(uuid)` and is silently swallowed.
     * `uuid` and both timestamps are named explicitly for that reason.
     *
     * ⚠️ AND THE WRITE HAS TWO DISTINCT FAILURES. A refused count is the
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

        /*
        | ⚠️ THE DEADLINE IS min(now + ttl, starts_at). A request for tomorrow at
        | six with a forty-eight-hour ttl used to wait until the day after the
        | lesson — pending over an hour that had already gone, holding a slot of
        | the student's ceiling and offering the teacher an «accept» that could
        | only schedule a lesson in the past. The sweep now closes it at the
        | lesson's own start at the latest.
        */
        $expiresAt = $now->copy()->addHours($this->settings->privateRequestTtlHours());

        if ($startsAt->lessThan($expiresAt)) {
            $expiresAt = $startsAt->utc()->toMutable();
        }

        $row = [
            'workspace_id' => $course->workspace_id,
            'uuid' => $uuid,
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'teacher_profile_id' => $teacherProfileId,
            'starts_at' => $startsAt->utc()->format('Y-m-d H:i:s'),
            'duration_minutes' => $minutes,
            'status' => PrivateSessionRequest::PENDING,
            'expires_at' => $expiresAt->utc()->format('Y-m-d H:i:s'),
            'pending_slot' => 0,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];

        try {
            $written = DB::transaction(function () use ($row, $teacherProfileId, $student, $limit): bool {
                // The gate — the transaction's FIRST statement, see above.
                // `SET id = id` changes nothing and still takes the row's
                // exclusive lock until commit; its affected-row count means
                // nothing (MySQL reports CHANGED rows), so it is not read.
                DB::update('UPDATE teacher_profiles SET id = id WHERE id = ?', [$teacherProfileId]);

                $pending = DB::table('private_session_requests')
                    ->where('student_user_id', $student->getKey())
                    ->where('teacher_profile_id', $teacherProfileId)
                    ->where('status', PrivateSessionRequest::PENDING)
                    ->count();

                if ($pending >= $limit) {
                    return false;
                }

                DB::table('private_session_requests')->insert($row);

                return true;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('لديك طلب قائم على هذا الموعد بالفعل.');
        } catch (PDOException $e) {
            // A deadlock or lock wait the retries could not clear: neither the
            // ceiling nor a duplicate, and never a 500.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw new DomainException('تعذّر تسجيل الطلب. حاول مرة أخرى.');
        }

        if (! $written) {
            throw new DomainException('لديك '.CountedNoun::of($limit, ['one' => 'طلب واحد ينتظر', 'two' => 'طلبان ينتظران', 'few' => 'طلبات تنتظر', 'many' => 'طلباً تنتظر', 'other' => 'طلب ينتظر']).' الردّ عند هذا المدرّس. انتظر الردّ أو اسحب أحدها.');
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
