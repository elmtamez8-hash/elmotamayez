<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\PeriodicReviewData;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use DomainException;

/**
 * The teacher writes — or revises — one student's assessment for one period
 * (FR-028).
 *
 * ⚠️ IT ASKS `EnrollmentDirectory` BEFORE IT WRITES, AND THAT IS NOT A FORMALITY.
 * The student arrives as a uuid in a request body, so without the check any user's
 * uuid names a student this teacher may assess — and the response comes back
 * carrying their name, which is the identity probe NFR-001أ forbids. `CreateFreezePeriod`
 * asks the same question for the same reason; `exists:users,uuid` answers a
 * different one.
 *
 * ⚠️ AND IT DOES NOT PUBLISH. A row written here is a draft nobody but its author
 * may read; {@see PublishPeriodicReview} claims `published_at` with a conditional
 * UPDATE so the student and their guardian are told exactly once.
 */
class SubmitPeriodicReview extends Action
{
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly AssistantScopeDirectory $assistants,
    ) {}

    public function handle(Workspace $workspace, User $teacher, PeriodicReviewData $data): PeriodicReview
    {
        $student = User::query()->where('uuid', $data->studentUuid)->first();

        // One refusal for «no such person» and «not your student»: two answers
        // would tell a teacher which uuids name real accounts.
        // ⛔ AND A CONFINED ASSISTANT WRITES ONLY FOR A STUDENT OF THEIR OWN
        // COURSES (spec 010 · FR-005) — the same one refusal, for the same reason.
        if ($student === null
            || ! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $workspace->getKey())
            || ! $this->assistants->mayActOnStudent($teacher, (int) $workspace->getKey(), (int) $student->getKey())) {
            throw new DomainException('لا يوجد طالب مسجّل عندك بهذا المعرّف.');
        }

        if ($data->periodEnd < $data->periodStart) {
            throw new DomainException('نهاية الفترة قبل بدايتها.');
        }

        $axes = [
            'commitment' => $data->commitment,
            'participation' => $data->participation,
            'homework' => $data->homework,
            'improvement' => $data->improvement,
        ];

        foreach ($axes as $value) {
            // Enforced in the Action and not only in the FormRequest: the seeders
            // and Filament reach this method with no request behind them, and the
            // column is an unsignedTinyInteger that SQLite would store happily.
            if ($value < 1 || $value > 5) {
                throw new DomainException('كل محور يجب أن يكون بين ١ و٥.');
            }
        }

        /*
        | Keyed on the quadruple the unique index carries, so a second write for
        | the same period revises rather than colliding — the teacher edits a draft
        | until they publish it.
        */
        $review = PeriodicReview::query()->firstOrNew([
            'workspace_id' => $workspace->getKey(),
            'student_user_id' => $student->getKey(),
            'period_start' => $data->periodStart,
            'period_end' => $data->periodEnd,
        ]);

        // ⚠️ A PUBLISHED ROW IS NOT EDITABLE. The student has read it and their
        // guardian has been told; silently rewriting it afterwards would make the
        // notification a statement about something that no longer exists.
        if ($review->exists && $review->isPublished()) {
            throw new DomainException('لا يمكن تعديل تقييم منشور.');
        }

        /*
        | ⛔ AN ASSISTANT REVISES ONLY A DRAFT THEY WROTE (2026-09-30). The fill
        | below makes the writer the author, so without this an assistant could
        | take over the teacher's draft for the period and then publish it — the
        | way around `PeriodicReviewPolicy::publish()`'s author rule. The teacher
        | and the owner revise any draft, as before.
        */
        if ($review->exists
            && (int) $review->teacher_user_id !== (int) $teacher->getKey()
            && $this->assistants->isAssistantIn($teacher, (int) $workspace->getKey())) {
            throw new DomainException('هذه المسودة كتبها غيرك، ولا يعدّلها المساعد.');
        }

        $review->fill([
            'teacher_user_id' => $teacher->getKey(),
            ...$axes,
            'note' => $data->note,
        ])->save();

        return $review;
    }
}
