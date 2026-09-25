<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Traits\LogsActivity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;

/**
 * One student, one assignment, one later date (FR-047).
 *
 * ⚠️ SAME GUARD AS THE ACCOMMODATION, AND THE SAME REFUSAL. A bare student uuid
 * is an identity probe; the answer here is indistinguishable from "no such
 * student", because a distinct reply for "not yours" confirms the uuid names
 * somebody real.
 *
 * ⚠️ AND IT WRITES A ROW WHERE NONE EXISTS. An extension granted before the
 * student has handed anything in has nowhere else to live — putting it on the
 * assignment would extend it for the class, and holding it in memory until they
 * submit means the nightly sweep marks them missed in between, which is exactly
 * the person it was granted to.
 */
class GrantExtension extends Action
{
    use LogsActivity;

    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /**
     * @throws DomainException
     */
    public function handle(Assignment $assignment, User $actor, User $student, DateTimeInterface $until): Submission
    {
        $workspaceId = (int) $assignment->workspace_id;

        if (! $this->enrollments->hasActiveEnrollmentInWorkspace($student, $workspaceId)) {
            throw new DomainException('لا يوجد طالبٌ بهذا المعرّف.');
        }

        if (CarbonImmutable::instance($until)->isPast()) {
            throw new DomainException('التأجيل إلى موعدٍ مضى ليس تأجيلاً.');
        }

        $submission = Submission::query()->firstOrCreate(
            [
                'assignment_id' => $assignment->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'workspace_id' => $workspaceId,
                // ⚠️ `pending`, NEVER `missed`. The deadline this student is
                // measured against has just moved; stamping the old verdict on a
                // row created by the act of moving it is wrong from its first
                // second — and US7's gate reads `state`, so it would shut the
                // next session on the one student who was told otherwise.
                'state' => Submission::STATE_PENDING,
            ],
        );

        /*
        | ⚠️ AND A ROW THE SWEEP ALREADY MARKED `missed` GOES BACK TO `pending`.
        | `firstOrCreate` sets the state on INSERT only — so the commonest case,
        | the student who missed the deadline and then asked for more time, kept
        | `missed` under a fresh extension: the teacher's list said «لا شيء سُلّم»
        | beside the date they had just granted, and US7's gate, which reads
        | `state`, still shut the next session on them. Only a row with no hand-in
        | is touched; a real submission's verdict is a record of an event.
        */
        $fill = ['extension_until' => $until];

        if ($submission->state === Submission::STATE_MISSED && $submission->submitted_at === null) {
            $fill['state'] = Submission::STATE_PENDING;
        }

        $submission->forceFill($fill)->save();

        $this->logActivity('assignment.extension_granted', $submission, [
            'until' => CarbonImmutable::instance($until)->toIso8601String(),
            'by' => $actor->getKey(),
        ]);

        return $submission->refresh();
    }
}
