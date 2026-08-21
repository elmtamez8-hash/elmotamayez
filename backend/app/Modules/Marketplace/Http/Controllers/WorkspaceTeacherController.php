<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;

/**
 * The teachers of the CURRENT workspace, for a picker.
 *
 * ⚠️ NOT `PublicMarketplaceController::teachers`, WHICH ANSWERS A DIFFERENT
 * QUESTION. That one lists PUBLICLY LISTED teachers across every workspace — so
 * using it here would offer a scheduler other academies' teachers, and would omit
 * their own colleagues who are not publicly listed yet. Two lists, two purposes.
 *
 * ⚠️ AND THIS EXISTS BECAUSE THE SCHEDULING SCREEN HAD NO WAY TO NAME A TEACHER.
 * `/manage/sessions` asked the operator to TYPE `teacher_profile_id` — a raw
 * sequential database id — into a free text field, and both of its buttons were
 * disabled until it was filled. Nobody can know that number, so the screen was
 * unusable: the create button was permanently dead, for everyone.
 *
 * The isolation is `WorkspaceScope`, which applies here because the reader is a
 * workspace member. `members.view` is the permission: this is a list of the people
 * in your academy, and that is exactly what that permission governs.
 */
class WorkspaceTeacherController extends Controller
{
    public function index(): JsonResponse
    {
        /*
        | ⚠️ AUTHORISED HERE, NEVER WITH `can:` ROUTE MIDDLEWARE — and the first
        | attempt used the middleware and was answered 403 for a teacher who
        | demonstrably holds the permission (the very next request, POST
        | /class-sessions, passed its policy and reached validation).
        |
        | The cause is ordering. spatie runs in TEAM MODE, so a tenant role check
        | is meaningless until `EnsureCurrentWorkspace` has pushed the workspace
        | into the permission registrar's team id — and Laravel's `Authorize`
        | middleware is not in the priority list, so it runs BEFORE that. The team
        | id is still null, the role resolves to nothing, and every tenant
        | permission answers false.
        |
        | ⚠️ THIS IS ALSO WHY `can:` APPEARS NOWHERE ELSE IN THIS CODEBASE. Every
        | other route in every module authorises inside its controller. That was
        | not a style preference; it is the only thing that works.
        */
        $this->authorize('create', ClassSession::class);

        $teachers = TeacherProfile::query()
            ->with('user:id,first_name,last_name')
            ->orderBy('id')
            ->get(['id', 'uuid', 'user_id', 'search_name']);

        return response()->json([
            'data' => $teachers->map(function (TeacherProfile $profile): array {
                $user = $profile->user;

                return [
                    /*
                    | ⚠️ THE NUMERIC ID IS SENT, AND THAT IS A DELIBERATE EXCEPTION
                    | TO THE `HasUuid` RULE — narrowly, and only because the two
                    | endpoints that consume it already validate on `id`
                    | (`WorkspaceRules::exists('teacher_profiles')`, which defaults
                    | to the `id` column). Sending the uuid here would mean a picker
                    | whose value no endpoint accepts.
                    |
                    | It is not a leak: this list is workspace-scoped, so the ids
                    | it exposes are the ids of the reader's own colleagues — which
                    | they can already see by name. Changing the two request rules
                    | to take a uuid is the correct long-term move and is a
                    | separate change from making the screen work.
                    */
                    'id' => $profile->id,
                    'uuid' => $profile->uuid,
                    // The live name first; `search_name` is the denormalised copy
                    // the marketplace search reads, and it is the fallback for a
                    // profile whose account row is gone.
                    'name' => $user !== null
                        ? trim($user->first_name.' '.$user->last_name)
                        : (string) $profile->search_name,
                ];
            })->all(),
        ]);
    }
}
