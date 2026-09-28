<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;

/**
 * Which side of a private conversation a person writes from.
 *
 * A private thread has two sides and the student's side has two voices: the
 * student, and a guardian writing AS the student (owner decision 2026-09-28). The
 * conversation stays keyed on the child — `student_user_id` is always the child —
 * so a guardian never gets a thread of their own and the teacher sees one
 * history per child, whoever typed each line.
 *
 * ⚠️ THE GUARDIAN IS ASKED THROUGH `GuardianDirectory`, NEVER THROUGH
 * `parent_student_relations`. Identity owns that table and Constitution III keeps
 * Community out of it; the directory also carries the two conditions that make a
 * relation count — ACTIVE (the student accepted it) and the permission ticked —
 * and dropping either is a silent leak of a child's correspondence.
 */
class ConversationSides
{
    public function __construct(private readonly GuardianDirectory $guardians) {}

    /** The student themself, or a guardian authorised to write as them. */
    public function speaksForStudent(User $user, Conversation $conversation): bool
    {
        if ($conversation->student_user_id === null) {
            return false;
        }

        if ((int) $conversation->student_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->isAuthorisedGuardian($user, $conversation);
    }

    /**
     * Every guardian who may speak in this thread right now, by id.
     *
     * @return list<int>
     */
    public function guardianIdsFor(Conversation $conversation): array
    {
        if ($conversation->student_user_id === null) {
            return [];
        }

        $student = User::query()->find($conversation->student_user_id);

        if (! $student instanceof User) {
            return [];
        }

        return array_values($this->guardians
            ->authorisedGuardians($student, GuardianPermission::Messages)
            ->map(fn (User $guardian): int => (int) $guardian->getKey())
            ->all());
    }

    /**
     * An adult with an ACTIVE relation to this thread's student that carries
     * {@see GuardianPermission::Messages}.
     */
    public function isAuthorisedGuardian(User $user, Conversation $conversation): bool
    {
        if ($conversation->student_user_id === null
            || (int) $conversation->student_user_id === (int) $user->getKey()) {
            return false;
        }

        $student = User::query()->find($conversation->student_user_id);

        return $student instanceof User
            && $this->guardians->isAuthorised($user, $student, GuardianPermission::Messages);
    }
}
