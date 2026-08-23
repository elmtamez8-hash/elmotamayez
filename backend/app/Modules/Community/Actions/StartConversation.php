<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Data\StartConversationData;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Open the one private conversation between a student and a teacher's side, or
 * hand back the one that already exists.
 *
 * ⚠️ THE RACE IS DECLARED RATHER THAN HOPED AGAINST. Two devices press «راسل
 * المدرّس» in the same second: both look, both find nothing, both insert, and one
 * of them hits `unique(workspace_id, student_user_id)`. The losing path is the
 * INTERESTING one and it is written out — catch the violation, re-read, return
 * the winner. `BookSeat`'s idiom, and the reason the unique index exists at all
 * rather than a `firstOrCreate` that is only ever tested single-threaded.
 *
 * ⚠️ AND EVERY IDENTIFIER IS RESOLVED HERE, INSIDE THE ACTION, AFTER THE
 * AUTHORISATION. A student is a member of no workspace, so `WorkspaceScope` adds
 * no condition for them and an implicit binding would resolve any workspace's
 * row before a policy ran.
 */
class StartConversation extends Action
{
    public function handle(User $actor, StartConversationData $data): Conversation
    {
        $workspace = Workspace::query()->where('uuid', $data->workspaceUuid)->first();

        if (! $workspace instanceof Workspace) {
            throw new ModelNotFoundException('لم نجد هذا المدرّس.');
        }

        $student = $this->resolveStudent($actor, $data);

        $existing = $this->find((int) $workspace->getKey(), (int) $student->getKey());

        if ($existing instanceof Conversation) {
            // Authorised even on the existing row: an enrolment that has ended
            // must not open a new thread, and reopening one is opening it.
            Gate::forUser($actor)->authorize('post', $existing);

            return $existing;
        }

        /*
        | The policy is asked about a conversation that does not exist yet — the
        | same class, unsaved. That keeps ONE spelling of "may these two talk":
        | a second condition written here would be the answer the door does not
        | give, which is the defect `BookingEligibility` already recorded.
        */
        $candidate = new Conversation([
            'workspace_id' => $workspace->getKey(),
            'kind' => ConversationKind::Private,
            'student_user_id' => $student->getKey(),
        ]);

        Gate::forUser($actor)->authorize('post', $candidate);

        try {
            /*
            | ⚠️ THE THREAD AND ITS PARTICIPANT ROW ARE ONE WRITE. A crash between
            | them leaves a conversation the student can OPEN — the policy reads
            | `student_user_id` off the row — and can never SEE, because their list
            | filters on `conversation_participants`. Invisible, permanent, and
            | nothing anywhere reports it; the unique index then refuses to make a
            | second one.
            */
            DB::transaction(function () use ($candidate, $student): void {
                $candidate->save();

                /*
                | ⚠️ THE STUDENT GETS A PARTICIPANT ROW AND THE TEACHER DOES NOT,
                | and the asymmetry is the design. A student issues no
                | `workspace_id` condition, so their list has to be a filter on
                | this table; the teacher's side is derived from membership so it
                | stays true as assistants come and go.
                */
                ConversationParticipant::query()->create([
                    'conversation_id' => $candidate->getKey(),
                    'user_id' => $student->getKey(),
                ]);
            });
        } catch (QueryException $e) {
            // The loser. The row exists because somebody else just wrote it, so
            // re-read rather than deciding from a driver-specific error code —
            // whether the row is there is the check, and it is engine-agnostic.
            $winner = $this->find((int) $workspace->getKey(), (int) $student->getKey());

            if (! $winner instanceof Conversation) {
                throw $e;
            }

            return $winner;
        }

        return $candidate;
    }

    private function find(int $workspaceId, int $studentId): ?Conversation
    {
        return Conversation::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $studentId)
            ->where('kind', ConversationKind::Private->value)
            ->first();
    }

    private function resolveStudent(User $actor, StartConversationData $data): User
    {
        if ($data->studentUuid === null) {
            return $actor;
        }

        $student = User::query()->where('uuid', $data->studentUuid)->first();

        if (! $student instanceof User) {
            throw new ModelNotFoundException('لم نجد هذا الطالب.');
        }

        return $student;
    }
}
