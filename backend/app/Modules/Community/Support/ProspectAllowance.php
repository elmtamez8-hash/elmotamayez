<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\CountedNoun;

/**
 * How much a non-subscriber may still say before the teacher's side answers
 * (owner decision 2026-09-28).
 *
 * «SUBSCRIBER» IS `EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` — an
 * `active` or `completed` enrolment in any course of this workspace — and nothing
 * beside it, because the other two things a student can hold both imply one: a
 * live subscription opens its courses by WRITING an enrolment
 * (`Payments\Listeners\ActivateSubscription`), and a cohort can only be joined by
 * somebody already enrolled in its course (`Learning\Actions\JoinCohort`). A second
 * spelling here would be the predicate the policy does not use.
 *
 * ⚠️ THE BUDGET IS THE CONVERSATION'S, NOT THE PERSON'S. The student and their
 * guardian write in ONE thread (it is keyed on the child), so «three messages»
 * is three between them — a family of two does not get six. And every message
 * counts, hidden ones included: a moderator hiding a message must not refund the
 * budget of the person who wrote it.
 *
 * ⚠️ WHILE `staff_replied_at` IS NULL EVERY MESSAGE IN THE THREAD IS FROM THE
 * STUDENT'S SIDE — the first staff message stamps it, in the same transaction —
 * so the count is simply the thread's rows. That is also why it is read after
 * `PostMessage` takes the row lock and never before.
 */
class ProspectAllowance
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /** Whether this private thread's student does NOT study with its teacher. */
    public function isProspect(Conversation $conversation): bool
    {
        if ($conversation->kind !== ConversationKind::Private || $conversation->student_user_id === null) {
            return false;
        }

        $student = User::query()->find($conversation->student_user_id);

        return ! ($student instanceof User
            && $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $conversation->workspace_id));
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

        if ($conversation->staff_replied_at !== null) {
            return null;
        }

        return max(0, CommunitySettings::prospectMessageCap() - $this->sentSoFar((int) $conversation->getKey()));
    }

    /** Every message in the thread, hidden ones included. */
    public function sentSoFar(int $conversationId): int
    {
        return Message::query()
            ->withoutWorkspaceScope()
            ->where('conversation_id', $conversationId)
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
}
