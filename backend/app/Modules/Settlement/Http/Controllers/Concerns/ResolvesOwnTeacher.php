<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers\Concerns;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\Request;

/**
 * The teacher behind the bearer token, and no other.
 *
 * Every read in this module scopes to it. The workspace scope alone is not the
 * guarantee FR-019 asks for: a workspace can hold more than one teacher profile
 * — the seeded demo academy holds six — so "the teacher in this workspace" would
 * hand whichever came first to whoever asked. Matching on `user_id` makes the
 * answer the reader's own by construction, which is why no endpoint here takes a
 * `teacher` parameter at all.
 */
trait ResolvesOwnTeacher
{
    private function ownTeacherProfile(Request $request): ?TeacherProfile
    {
        return TeacherProfile::query()
            ->where('user_id', $this->currentUser($request)->getKey())
            ->first();
    }

    /**
     * The id to filter a list by.
     *
     * Zero for a reader with no teacher profile, deliberately: it matches no row,
     * so a member of the workspace who is not a teacher sees an empty list rather
     * than an unfiltered one. A null here would have to be handled at every call
     * site, and the one that forgot would return everything.
     */
    private function ownTeacherProfileId(Request $request): int
    {
        return (int) ($this->ownTeacherProfile($request)?->getKey() ?? 0);
    }
}
