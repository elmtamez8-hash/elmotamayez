<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Models\User;
use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A departed teacher is signed out everywhere (spec 013 · FR-037).
 *
 * ⚠️ THE TOKEN IS THE THING THAT IS DELETED, and the session row follows it rather
 * than the other way round. Spec 004 wrote the rule down: enforcement is deleting
 * the Sanctum token, because that is the one number a client cannot forge — a
 * session row marked ended with its token still live is a screen the person keeps
 * using until they happen to reload.
 *
 * ⚠️ AND THIS RUNS AT COMPLETION, NEVER AT REQUEST. The notice period exists so a
 * departing teacher can finish the lessons their students were promised; revoking
 * their tokens the moment they ask to leave locks them out of exactly that.
 */
class RevokeTeacherSessions
{
    public function handle(TeacherOffboardingCompleted $event): void
    {
        $teacherId = (int) $event->offboarding->teacher_user_id;

        /*
        | Every token, not the ones issued in this workspace: a Sanctum token is
        | not scoped to a workspace at all, so "the tokens belonging to this exit"
        | is not a set that exists. A teacher leaving one workspace while teaching
        | in another signs in again — which is the correct outcome, and the
        | membership half below is what keeps the departed workspace closed to them.
        */
        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $teacherId)
            ->delete();

        AuthSession::query()
            ->where('user_id', $teacherId)
            ->whereNull('ended_at')
            ->update([
                'status' => AuthSession::STATUS_ENDED,
                'ended_at' => now(),
                'ended_reason' => SessionEndReason::Offboarding->value,
                'updated_at' => now(),
            ]);
    }
}
