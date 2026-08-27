<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Learning\Models\Cohort;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

/**
 * The group's thread — found, or opened by whoever arrives first (FR-046).
 *
 * `ResolveSessionConversation` in a second shape, and deliberately not a fourth
 * branch inside it: that class resolves by a column it is handed, and a third
 * caller passing a third column name is a signature nobody reads. The two share
 * the SHAPE, which is the part that matters:
 *
 * ⚠️ NO `conversation_participants` ROWS ARE WRITTEN ON JOINING. Entitlement is
 * derived from the membership; that table tracks who has read what. A group of
 * thirty would otherwise mean thirty rows written at creation and thirty more
 * every time somebody transfers, each one a second answer to a question the
 * membership already settles — and the day the two disagree, the row wins and the
 * membership does not.
 *
 * ⚠️ AND ENTITLEMENT IS ASKED BEFORE THE ROW EXISTS, through the same policy the
 * door uses — the unsaved candidate is authorised. A second condition written
 * here would be the answer on the screen and not the answer at `ReadMessages`.
 *
 * ⚠️ THE INSERT IS A RACE AND THE INDEX IS THE GUARD. Two members tap the tab in
 * the same second, both find nothing, both insert; `unique(cohort_id)` is what
 * makes the loser catchable, and the loser re-reads rather than throwing.
 */
class ResolveCohortConversation extends Action
{
    public function handle(User $actor, string $cohortUuid): Conversation
    {
        $cohort = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $cohortUuid)
            ->first();

        if (! $cohort instanceof Cohort) {
            throw new ModelNotFoundException('لم نجد هذه المجموعة.');
        }

        $existing = $this->find((int) $cohort->getKey());

        if ($existing instanceof Conversation) {
            Gate::forUser($actor)->authorize('view', $existing);

            return $existing;
        }

        $candidate = new Conversation([
            'workspace_id' => (int) $cohort->workspace_id,
            'kind' => ConversationKind::Cohort,
            'cohort_id' => (int) $cohort->getKey(),
        ]);

        Gate::forUser($actor)->authorize('view', $candidate);

        try {
            $candidate->save();
        } catch (QueryException $e) {
            // The declared loser. Whether the row is there is the check — never a
            // driver-specific error code.
            $winner = $this->find((int) $cohort->getKey());

            if (! $winner instanceof Conversation) {
                throw $e;
            }

            return $winner;
        }

        return $candidate;
    }

    private function find(int $cohortId): ?Conversation
    {
        return Conversation::query()
            ->withoutWorkspaceScope()
            ->where('cohort_id', $cohortId)
            ->first();
    }
}
