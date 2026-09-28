<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Actions\SaveAccountPhoto;
use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The public URL of a person's account photo, and the eager load that makes it
 * free to read.
 *
 * ⚠️ ONE ORDER, AND IT IS THE WRITER'S. {@see SaveAccountPhoto}
 * writes a teacher's photo to `teacher_profiles.photo_path` and everybody else's
 * to `student_profiles.avatar_path`; reading them in any other order is an account
 * that uploads a face in one place and is shown an initial from the other. So a
 * teacher profile, when there is one, is the answer even while its photo is empty.
 *
 * ⚠️ AND THE EAGER LOAD IGNORES THE WORKSPACE SCOPE. `teacher_profiles` is
 * workspace-owned, and a reader signed into ANOTHER workspace — a teacher who is
 * also somebody's student, a student who is a member somewhere — would otherwise
 * load no profile at all and see the initial instead of the face, with nothing
 * saying why. A profile photo is public by design (it is on the marketplace
 * card); the scope protects nothing here and hides the picture.
 */
final class AccountPhoto
{
    public static function url(User $user): ?string
    {
        $path = $user->teacherProfile === null
            ? $user->studentProfile?->avatar_path
            : $user->teacherProfile->photo_path;

        return $path === null || $path === '' ? null : asset('storage/'.$path);
    }

    /**
     * The relations `url()` reads, under a User relation of the caller's.
     *
     * `user_id` is selected because the relation matches on it; the `User` itself
     * is never column-limited here (a limited `users` select renders blank names
     * — `docs/gotchas/database.md`).
     *
     * @return array<string, \Closure(Relation<*, *, *>): mixed>
     */
    public static function eagerLoads(string $userRelation): array
    {
        return [
            $userRelation.'.teacherProfile' => fn (Relation $query) => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->select(['id', 'user_id', 'photo_path']),
            $userRelation.'.studentProfile' => fn (Relation $query) => $query
                ->select(['id', 'user_id', 'avatar_path']),
        ];
    }
}
