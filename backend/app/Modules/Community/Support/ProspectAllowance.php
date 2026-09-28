<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\CountedNoun;
use Carbon\CarbonImmutable;

/**
 * How much a non-subscriber may still say before the teacher's side answers
 * (owner decisions 2026-09-28).
 *
 * «SUBSCRIBER» IS `EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` — an
 * `active` or `completed` enrolment in any course of this workspace — and nothing
 * beside it, because the other two things a student can hold both imply one: a
 * live subscription opens its courses by WRITING an enrolment
 * (`Payments\Listeners\ActivateSubscription`), and a cohort can only be joined by
 * somebody already enrolled in its course (`Learning\Actions\JoinCohort`).
 *
 * ⛔ THE BUDGET RUNS FROM THE MOMENT THE STUDENT BECAME A PROSPECT — the «epoch».
 * For somebody who never studied here it is the beginning of the thread; for a
 * former student it is when their last enrolment here stopped granting access
 * (`EnrollmentDirectory::accessEndedAt()`). Their messages are counted from the
 * epoch, and only a teacher-side message written AFTER it lifts the cap: a reply
 * from the months they were enrolled answers nothing they are asking now (owner
 * decision — a former student starts from scratch). Derived at read time from
 * two timestamps rather than stamped on the conversation, so no deploy, no
 * backfill and no missed «enrolment ended» event can hand anybody unlimited
 * writing.
 *
 * ⚠️ THE BUDGET IS THE CONVERSATION'S, NOT THE PERSON'S. The student and their
 * guardian write in ONE thread, so «three messages» is three between them. And
 * every message counts, hidden ones included: a moderator hiding a message must
 * not refund the budget of the person who wrote it. Until a teacher-side message
 * exists after the epoch, every message after it is from the student's side, so
 * the count is simply the thread's rows since then.
 */
class ProspectAllowance
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /** Whether this private thread's student does NOT study with its teacher. */
    public function isProspect(Conversation $conversation): bool
    {
        $student = $this->student($conversation);

        if ($student === null) {
            return false;
        }

        return ! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $conversation->workspace_id);
    }

    /** When the student became a prospect; null = the beginning of the thread. */
    public function epoch(Conversation $conversation): ?CarbonImmutable
    {
        $student = $this->student($conversation);

        return $student === null
            ? null
            : $this->enrollments->accessEndedAt($student, (int) $conversation->workspace_id);
    }

    /**
     * Whether the cap refuses one more message now. The count `PostMessage`
     * trusts is this one, asked AFTER it takes the conversation's row lock.
     */
    public function exhausted(int $conversationId, ?CarbonImmutable $epoch): bool
    {
        if ($this->teacherAnsweredSince($conversationId, $epoch)) {
            return false;
        }

        return $this->sentSince($conversationId, $epoch) >= CommunitySettings::prospectMessageCap();
    }

    /**
     * Messages the student's side may still send, or null when unlimited.
     *
     * A display answer — for the button and the composer. The refusal that
     * counts is `PostMessage`'s, under the lock.
     */
    public function remaining(Conversation $conversation): ?int
    {
        if (! $this->isProspect($conversation)) {
            return null;
        }

        if (! $conversation->exists) {
            return CommunitySettings::prospectMessageCap();
        }

        $epoch = $this->epoch($conversation);
        $id = (int) $conversation->getKey();

        if ($this->teacherAnsweredSince($id, $epoch)) {
            return null;
        }

        return max(0, CommunitySettings::prospectMessageCap() - $this->sentSince($id, $epoch));
    }

    public function teacherAnsweredSince(int $conversationId, ?CarbonImmutable $epoch): bool
    {
        return Message::query()
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
            ->where('from_staff', true)
            // Strictly after: a reply in the very second access ended is a reply
            // to the student they were, not to the prospect they became.
            ->when($epoch !== null, fn ($query) => $query->where('created_at', '>', $epoch?->format('Y-m-d H:i:s')))
            ->exists();
    }

    /** Every message since the epoch, hidden ones included. */
    public function sentSince(int $conversationId, ?CarbonImmutable $epoch): int
    {
        return Message::query()
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
            ->when($epoch !== null, fn ($query) => $query->where('created_at', '>=', $epoch?->format('Y-m-d H:i:s')))
            ->count();
    }

    /** The sentence a capped sender reads — the same one on the button and at the door. */
    public static function refusal(int $cap): string
    {
        return 'أرسلتَ '.CountedNoun::of($cap, [
            'one' => 'رسالة واحدة',
            'two' => 'رسالتين',
            'few' => 'رسائل',
            'many' => 'رسالة',
            'other' => 'رسالة',
        ]).' ولم يردّ المدرّس بعد. ستتمكّن من المتابعة بعد أوّل ردّ منه.';
    }

    private function student(Conversation $conversation): ?User
    {
        if ($conversation->kind !== ConversationKind::Private || $conversation->student_user_id === null) {
            return null;
        }

        $student = User::query()->find($conversation->student_user_id);

        return $student instanceof User ? $student : null;
    }
}
