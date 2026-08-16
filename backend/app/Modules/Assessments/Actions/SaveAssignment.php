<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;

/**
 * Writes a piece of homework, and publishes it (FR-043 · FR-046).
 *
 * ⚠️ THE PENALTY PAIR IS VALIDATED HERE, NOT ONLY IN THE FORM REQUEST. A rate
 * with no cap is the −100٪ submission FR-046أ forbids, and the seeder, the panel
 * and any later import reach this Action without passing a request at all.
 */
class SaveAssignment extends Action
{
    use LogsActivity;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DomainException
     */
    public function handle(int $workspaceId, User $author, array $data, ?Assignment $assignment = null): Assignment
    {
        $type = (string) ($data['submission_type'] ?? Assignment::TYPE_TEXT);
        $policy = (string) ($data['late_policy'] ?? Assignment::LATE_ACCEPT);

        /*
        | ⚠️ TWO OF FR-044'S THREE, AND THE THIRD IS REFUSED RATHER THAN FAKED.
        | «مجموعة أسئلة من البنك» has no flow behind it — no item table, no
        | attempt, no marking path — so accepting the value would give the
        | teacher a plain text box under a label promising bank questions, and
        | the student something to type into that nobody asked for. A refusal
        | names the gap; a silent fallback hides it until a mark is disputed.
        */
        if (! in_array($type, [Assignment::TYPE_TEXT, Assignment::TYPE_FILE], true)) {
            throw new DomainException('نوع تسليم غير مدعوم بعد.');
        }

        if (! in_array($policy, [Assignment::LATE_ACCEPT, Assignment::LATE_REJECT, Assignment::LATE_PENALTY], true)) {
            throw new DomainException('سياسة تأخير غير معروفة.');
        }

        $perDay = (float) ($data['late_penalty_pct_per_day'] ?? 0);
        $cap = (float) ($data['late_penalty_cap_pct'] ?? 100);

        if ($policy === Assignment::LATE_PENALTY && $perDay <= 0) {
            throw new DomainException('سياسة الخصم بلا نسبة لا تخصم شيئاً.');
        }

        if ($cap < 0 || $cap > 100) {
            throw new DomainException('سقف الخصم بين صفر ومئة.');
        }

        $attributes = [
            'workspace_id' => $workspaceId,
            'course_id' => $data['course_id'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'class_session_id' => $data['class_session_id'] ?? null,
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'points' => (int) ($data['points'] ?? 10),
            'due_at' => $data['due_at'] ?? null,
            'submission_type' => $type,
            'late_policy' => $policy,
            'late_penalty_pct_per_day' => $perDay,
            'late_penalty_cap_pct' => $cap,
        ];

        if ($assignment === null) {
            $assignment = Assignment::create([
                ...$attributes,
                'status' => Assignment::STATUS_DRAFT,
                'created_by' => $author->getKey(),
            ]);

            $this->logActivity('assignment.created', $assignment);

            return $assignment;
        }

        $assignment->update($attributes);
        $this->logActivity('assignment.updated', $assignment);

        return $assignment->refresh();
    }

    /**
     * ⚠️ PUBLISHING IS WHAT MAKES IT BLOCK ANYTHING. Until this runs, US7's gate
     * ignores the assignment entirely (FR-042) — which is why it is a separate
     * act rather than a field on the form: a teacher half-way through writing
     * homework must not be one autosave away from locking their class out of the
     * next session.
     */
    public function publish(Assignment $assignment): Assignment
    {
        if ($assignment->due_at === null) {
            throw new DomainException('واجبٌ بلا موعد لا يُنشر: لا شيء يمرّ، فلا يُسلَّم متأخراً ولا يُوسَم غائباً.');
        }

        $assignment->update([
            'status' => Assignment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->logActivity('assignment.published', $assignment);

        return $assignment->refresh();
    }
}
