<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Events\AssignmentSubmitted;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Support\AssignmentDeadline;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A student hands work in (FR-044 · FR-045 · FR-047).
 *
 * ⚠️ THE STATE IS STAMPED AT THE MOMENT IT HAPPENS AND NEVER RECOMPUTED. A
 * teacher who softens the deadline in week ten must not turn week three's late
 * hand-ins into punctual ones — and one who tightens it must not do the reverse.
 * `state` and `late_by_minutes` are a record of an event, not a view of a policy.
 *
 * ⚠️ AND THE DEADLINE IT IS MEASURED AGAINST IS THE STUDENT'S OWN. FR-047 says a
 * hand-in inside an extension is not late; measuring against the raw `due_at`
 * would mark the one student the extension was granted for, which is the whole
 * failure the extension exists to prevent.
 */
class SubmitAssignment extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly AssignmentDeadline $deadlines,
    ) {}

    /**
     * @throws DomainException when the assignment is not open, the payload does not
     *                         match its type, or the work has already been marked
     */
    public function handle(Assignment $assignment, User $student, ?string $answerText = null, ?UploadedFile $file = null): Submission
    {
        if (! $assignment->isPublished()) {
            throw new DomainException('هذا الواجب لم يُنشر بعد.');
        }

        if ($assignment->submission_type === Assignment::TYPE_FILE && $file === null) {
            throw new DomainException('هذا الواجب يُسلَّم ملفاً.');
        }

        if ($assignment->submission_type !== Assignment::TYPE_FILE && ($answerText === null || trim($answerText) === '')) {
            throw new DomainException('اكتب إجابتك قبل التسليم.');
        }

        $studentId = (int) $student->getKey();
        $existing = Submission::query()
            ->where('assignment_id', $assignment->getKey())
            ->where('student_user_id', $studentId)
            ->first();

        /*
        | ⚠️ RESUBMISSION IS ALLOWED UNTIL IT IS MARKED, AND REFUSED AFTER. A
        | student who spots a mistake ten minutes later should fix it; a student
        | who replaces the file the teacher has already read and scored is
        | rewriting the evidence behind a mark that has been told to them.
        */
        if ($existing?->isGraded() === true) {
            throw new DomainException('صُحّح هذا التسليم، فلا يُستبدل.');
        }

        $deadline = $this->deadlines->effectiveFor($assignment, $studentId, $existing);
        $lateMinutes = $this->deadlines->lateByMinutes($deadline);

        if ($lateMinutes > 0 && ! $assignment->acceptsLate()) {
            throw new DomainException('انقضى موعد التسليم، وهذا الواجب لا يقبل المتأخر.');
        }

        $values = [
            'state' => $lateMinutes > 0 ? Submission::STATE_LATE : Submission::STATE_ON_TIME,
            'answer_text' => $assignment->submission_type === Assignment::TYPE_FILE ? null : $answerText,
            'submitted_at' => now(),
            'late_by_minutes' => $lateMinutes,
        ];

        $submission = DB::transaction(function () use ($assignment, $studentId, $existing, $values): Submission {
            if ($existing === null) {
                /*
                | ⚠️ TWO TAPS ON A FIRST HAND-IN BOTH READ NULL. The insert is
                | still the right move — there is nothing to claim — but the
                | loser of the race hits `unique(assignment_id, student_user_id)`
                | and a bare QueryException is a raw 500 on the student's screen,
                | which this codebase forbids outright. Catching it and falling
                | through to the claim turns a collision into what it actually
                | is: a second hand-in against a row that now exists.
                */
                try {
                    return Submission::create([
                        'workspace_id' => $assignment->workspace_id,
                        'assignment_id' => $assignment->getKey(),
                        'student_user_id' => $studentId,
                        ...$values,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $existing = Submission::query()
                        ->where('assignment_id', $assignment->getKey())
                        ->where('student_user_id', $studentId)
                        ->sole();
                }
            }

            /*
            | ⚠️ THE SWEEP MAY HAVE WRITTEN THIS ROW LAST NIGHT. Claiming it with
            | `WHERE submitted_at IS NULL` in the same statement that fills it is
            | what makes a hand-in and a second tap not become two; a false
            | return is not a failure here, it means the row already carries a
            | hand-in and this is a replacement of it.
            */
            if (! $existing->claimForSubmission($values)) {
                $existing->update($values);
            }

            return $existing->refresh();
        });

        if ($file !== null) {
            // On the private disk, single file: a resubmission replaces rather
            // than accumulating copies nobody can tell apart (FR-048).
            $submission->addMedia($file)->toMediaCollection('submission');
        }

        $this->logActivity('assignment.submitted', $submission, [
            'state' => $submission->state,
            'late_by_minutes' => $submission->late_by_minutes,
        ]);

        event(new AssignmentSubmitted($submission));

        return $submission;
    }
}
