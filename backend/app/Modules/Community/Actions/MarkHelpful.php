<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Events\HelpfulAnswerMarked;
use App\Modules\Community\Models\Message;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * «هذه الإجابة مفيدة» — the teacher's endorsement (`FR-023`).
 *
 * ⚠️ THE CONDITIONAL UPDATE IS THE DEDUPLICATION, and the event is fired by its
 * winner alone. Two taps on a phone, or a double click, are the ordinary case;
 * `WHERE is_helpful = 0` reports one affected row the first time and zero the
 * second, so nothing downstream ever hears about the second press. The award key
 * in `award_entries` is a second layer that exists because this one can be got
 * wrong — not a substitute for getting it right.
 */
class MarkHelpful extends Action
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function handle(User $actor, string $messageUuid): Message
    {
        $message = Message::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $messageUuid)
            ->first();

        if (! $message instanceof Message) {
            throw new ModelNotFoundException('لم نجد هذه الرسالة.');
        }

        Gate::forUser($actor)->authorize('markHelpful', $message);

        $claimed = Message::query()
            ->withoutWorkspaceScope()
            ->whereKey($message->getKey())
            ->where('is_helpful', false)
            ->update(['is_helpful' => true]);

        if ($claimed === 1) {
            /*
            | ⚠️ AND ONLY FOR A STUDENT OF THIS TEACHER. Points and coins go into a
            | purse that is per teacher, and a member of the teacher's own side has
            | none — `AwardPoints` would be asked to file coins with nowhere to put
            | them. Marking one's own answer useful stays allowed, because it is how
            | a teacher pins the correct explanation; it simply pays nobody.
            |
            | ⚠️ THE PREDICATE IS THE ENROLMENT, NOT «is this person a workspace
            | member». A student is a member of no workspace in production, so the
            | membership form looks right and is invisible-wrong in a fixture, where
            | `addWorkspaceMember()` attaches one — and there it silently paid
            | nobody at all. Asked through the contract, which is also the only
            | reading Community is allowed.
            */
            $sender = $message->sender;

            $senderIsStudent = $sender !== null
                && $this->enrollments->hasActiveEnrollmentInWorkspace($sender, (int) $message->workspace_id);

            if ($senderIsStudent) {
                event(new HelpfulAnswerMarked(
                    studentUserId: (int) $message->sender_user_id,
                    workspaceId: (int) $message->workspace_id,
                    sourceType: 'message',
                    sourceId: (int) $message->getKey(),
                ));
            }
        }

        return $message->refresh();
    }
}
