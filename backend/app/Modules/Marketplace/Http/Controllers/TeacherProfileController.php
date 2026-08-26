<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\UpdateTeacherSlug;
use App\Modules\Marketplace\Http\Requests\UpdateTeacherSlugRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teacher's own listing, edited by the teacher.
 *
 * Separate from TeacherApplicationController, which owns the four-step wizard:
 * these are edits to a profile that already exists, and a method that means
 * "change my public URL" sitting among the application steps reads as a fifth
 * step nobody has to complete.
 */
class TeacherProfileController extends Controller
{
    /**
     * What the settings card needs to render itself.
     *
     * `slug` is null for an account with no listing yet — an application still
     * in review — and the card says so rather than offering a field that saves
     * into nothing. Deliberately NOT part of `/auth/me`: a slug is true of a
     * teacher and of nobody else, and `users` payloads carry what every account
     * has.
     *
     * ⚠️ AND «NO ROW» IS A REFUSAL, NOT A NULL SLUG. The two are one enum apart
     * on the wire and worlds apart on the screen: a pending teacher HAS a row
     * (`SubmitTeacherApplication` creates it, slug null, pending) and is told
     * «لم يُنشر ملفك بعد»; a student has no row at all, and answering them with
     * the same body printed «رابط ملفك العام» on a student's settings page,
     * under a heading about the address parents reach *their* page at.
     *
     * `PublicProfileUrlCard` was written against this refusal and says so in as
     * many words — «a student opening /settings gets a 403 here» — and the
     * `.catch` it swallows the 403 with never ran, because the 403 did not
     * exist. A documented guard that is not implemented is worse than an absent
     * one: it ends the review that would have found it. Same spelling as
     * `updateSlug()` one method below, which had it from the start.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->currentUser($request)->teacherProfile;

        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        return response()->json([
            'slug' => $profile->slug,
            'is_publicly_listed' => (bool) $profile->is_publicly_listed,
        ]);
    }

    /**
     * Change the segment the public profile lives at.
     *
     * ⚠️ The profile comes from the authenticated user, never from a route
     * parameter. A `PUT /teachers/{uuid}/slug` would need an ownership check
     * that someone eventually forgets; with no parameter there is nothing to
     * tamper with and no check to forget.
     */
    public function updateSlug(UpdateTeacherSlugRequest $request, UpdateTeacherSlug $action): JsonResponse
    {
        $profile = $request->profile();

        // 403, not 404: the account exists and is signed in, it simply has no
        // listing to rename. A teacher whose application is still in review is
        // the ordinary case here.
        abort_if($profile === null, 403, 'لا يوجد ملف مدرّس لهذا الحساب.');

        $updated = $action->handle($profile, $request->slug());

        return response()->json(['slug' => $updated->slug]);
    }
}
