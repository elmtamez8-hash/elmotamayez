<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\ModerationActionData;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\Message;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Support\BanReader;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Hiding a message, banning a participant, and lifting a ban (`FR-021`).
 *
 * ⚠️ ALL THREE ARE ROWS IN ONE TABLE, and there is deliberately no
 * `DELETE /moderation/bans/{ban}`. The table IS the record the requirement asks
 * for — who acted, on whom, why — so a lift that deleted the ban row would erase
 * the fact that somebody was banned and the reason given, on the day they were
 * forgiven. `ModerationAction` refuses `update` and `delete` on the model for the
 * same reason: an Action is one caller, a model is every caller.
 */
class ModerateMessage extends Action
{
    public function __construct(private readonly BanReader $bans) {}

    public function handle(User $actor, ModerationActionData $data): ModerationAction
    {
        [$workspaceId, $subjectId] = $this->resolveSubject($data);

        // The permission is workspace-shaped, so it is asked about the workspace
        // the SUBJECT lives in — never about whichever one the moderator's
        // context happens to be pointing at.
        Gate::forUser($actor)->authorize('moderate', [ModerationAction::class, $workspaceId]);

        $action = ModerationAction::query()->create([
            'workspace_id' => $workspaceId,
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $data->subjectType,
            'subject_id' => $subjectId,
            'verdict' => $data->verdict,
            'reason' => $data->reason,
            // Null is permanent. `BanReader` reads it with a grouped predicate,
            // because `NULL > now()` is NULL and would end it immediately.
            'expires_at' => $data->expiresAt === null ? null : CarbonImmutable::parse($data->expiresAt),
        ]);

        if ($data->verdict === ModerationVerdict::Hidden) {
            // Claimed, not assigned: a second verdict on the same message must
            // not restamp the moment its words stopped being readable.
            Message::query()
                ->withoutWorkspaceScope()
                ->whereKey($subjectId)
                ->whereNull('hidden_at')
                ->update(['hidden_at' => now()]);
        }

        if ($data->verdict === ModerationVerdict::Banned || $data->verdict === ModerationVerdict::Unbanned) {
            // The reader memoises per request, and the moderator's own next read
            // is inside this one.
            $this->bans->forget($subjectId, $workspaceId);
        }

        return $action;
    }

    /**
     * @return array{0: int, 1: int} the workspace the decision belongs to, and the subject's id
     */
    private function resolveSubject(ModerationActionData $data): array
    {
        if ($data->subjectType === ModerationAction::SUBJECT_MESSAGE) {
            $message = Message::query()
                ->withoutWorkspaceScope()
                ->where('uuid', $data->subjectUuid)
                ->first();

            if (! $message instanceof Message) {
                throw new ModelNotFoundException('لم نجد هذه الرسالة.');
            }

            return [(int) $message->workspace_id, (int) $message->getKey()];
        }

        if ($data->subjectType !== ModerationAction::SUBJECT_USER) {
            throw new DomainException('نوع غير معروف لموضوع الإجراء.');
        }

        $subject = User::query()->where('uuid', $data->subjectUuid)->first();

        if (! $subject instanceof User) {
            throw new ModelNotFoundException('لم نجد هذا الحساب.');
        }

        /*
        | ⚠️ THE WORKSPACE COMES FROM THE MODERATOR'S CONTEXT FOR A PERSON, because
        | a person belongs to no one workspace — a student studies with several.
        | It is the only input here that is not derived from the subject, and the
        | policy is asked about it immediately afterwards, so a moderator cannot
        | ban somebody out of a workspace they do not moderate.
        */
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            throw new DomainException('لا توجد مساحة عمل حالية لتنفيذ هذا الإجراء.');
        }

        return [$workspaceId, (int) $subject->getKey()];
    }
}
