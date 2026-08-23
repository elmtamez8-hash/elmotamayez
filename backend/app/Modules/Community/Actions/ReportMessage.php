<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * «أبلِغ عن هذه الرسالة» — the human path for what the list did not catch
 * (`FR-024`).
 *
 * ⚠️ A REPORT IS A ROW, NOT A DELETION. It raises a `Reported` verdict for a
 * moderator to look at; the message stays visible until a person decides
 * otherwise, because a report that hid content on submission is a mute button
 * handed to whoever is loudest in the room.
 *
 * ⚠️ AND IT CARRIES ITS OWN RATE LIMITER, `chat-report`, deliberately separate
 * from `chat-write`. Somebody throttled for writing must still be able to report
 * what is being written at them — share one counter and the loudest participant
 * silences the complaint about themselves.
 *
 * The reporter must be able to READ the message. Reporting one they cannot see is
 * a probe of another room's contents by uuid.
 */
class ReportMessage extends Action
{
    public function handle(User $reporter, string $messageUuid, ?string $reason): ModerationAction
    {
        $message = Message::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $messageUuid)
            ->with('conversation')
            ->first();

        if (! $message instanceof Message || $message->conversation === null) {
            throw new ModelNotFoundException('لم نجد هذه الرسالة.');
        }

        Gate::forUser($reporter)->authorize('view', $message->conversation);

        return ModerationAction::query()->create([
            'workspace_id' => $message->workspace_id,
            'actor_user_id' => $reporter->getKey(),
            'subject_type' => ModerationAction::SUBJECT_MESSAGE,
            'subject_id' => $message->getKey(),
            'verdict' => ModerationVerdict::Reported,
            'reason' => $reason,
        ]);
    }
}
