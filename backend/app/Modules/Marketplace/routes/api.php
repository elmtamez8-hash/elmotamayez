<?php

declare(strict_types=1);

use App\Modules\Marketplace\Http\Controllers\PublicMarketplaceController;
use App\Modules\Marketplace\Http\Controllers\ReviewController;
use App\Modules\Marketplace\Http\Controllers\SignupTaxonomyController;
use App\Modules\Marketplace\Http\Controllers\TeacherApplicationController;
use App\Modules\Marketplace\Http\Controllers\TeacherProfileController;
use App\Modules\Marketplace\Http\Controllers\TeacherReviewController;
use App\Modules\Marketplace\Http\Controllers\WorkspaceTeacherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketplace (no authentication)
|--------------------------------------------------------------------------
|
| These routes serve anonymous visitors, which means WorkspaceScope filters
| nothing on them — it returns early when there is no authenticated user. The
| replacement guard is the publiclyListed() scope inside each Action.
|
| Throttling is by IP because there is no actor to key on. Without it the whole
| teacher directory can be scraped in a few minutes.
|
*/

Route::middleware('throttle:public')->prefix('marketplace')->name('marketplace.')->group(function (): void {
    Route::get('/home', [PublicMarketplaceController::class, 'home'])->name('home');
    Route::get('/stats', [PublicMarketplaceController::class, 'stats'])->name('stats');
    Route::get('/subjects', [PublicMarketplaceController::class, 'subjects'])->name('subjects');
    Route::get('/grade-levels', [PublicMarketplaceController::class, 'gradeLevels'])->name('grade-levels');
    // Spec 011 · FR-042 — read by the registration form before there is an
    // account, so it sits with the other public catalogue reads and under the
    // same named limiter.
    Route::get('/regions', [PublicMarketplaceController::class, 'regions'])->name('regions');
    Route::get('/teachers', [PublicMarketplaceController::class, 'teachers'])->name('teachers.index');
    Route::get('/courses', [PublicMarketplaceController::class, 'courses'])->name('courses.index');

    // Bound as a plain string, not a route model: implicit binding resolves by uuid
    // without the publiclyListed() guard, which would make unpublished profiles
    // reachable by url.
    Route::get('/teachers/{uuid}', [PublicMarketplaceController::class, 'teacher'])->name('teachers.show');

    // Spec 023 · FR-001. `uuid` and never `slug`: `courses.slug` is unique per
    // (workspace_id, slug) — inside one workspace only — so two teachers naming
    // a course «الرياضيات ٣» produce the same slug and a public route has no
    // workspace to tell them apart. Bound as a plain string for the same reason
    // the teacher route is: implicit binding resolves by uuid WITHOUT the
    // publiclyListed() guard, which would serve every draft by url.
    Route::get('/courses/{uuid}', [PublicMarketplaceController::class, 'course'])->name('courses.show');
});

/*
|--------------------------------------------------------------------------
| Signup vocabulary (no authentication) — spec 022 · FR-002
|--------------------------------------------------------------------------
|
| ⚠️ DELIBERATELY NOT THE `/marketplace` READS ABOVE. Those drop every entry
| with no publicly listed teacher, which is right for a filter bar and is a
| CIRCULAR LOCK on a required signup field: no listed teacher means no subject
| in the list means the first teacher on the platform can never apply.
|
| Same limiter, same absence of authentication — a registration form needs the
| list before there is an account. None of the three takes a query parameter.
|
*/

Route::middleware('throttle:public')->prefix('signup')->name('signup.')->group(function (): void {
    Route::get('/subjects', [SignupTaxonomyController::class, 'subjects'])->name('subjects');
    Route::get('/grade-levels', [SignupTaxonomyController::class, 'gradeLevels'])->name('grade-levels');
    Route::get('/school-years', [SignupTaxonomyController::class, 'schoolYears'])->name('school-years');
});

/*
|--------------------------------------------------------------------------
| Teacher application wizard
|--------------------------------------------------------------------------
|
| Step 1 creates the account, so it is public; the rest are authenticated as
| the applicant. Every step resolves the application from the token, never from
| a route parameter — there is nothing to tamper with.
|
*/

Route::post('/auth/register/teacher/step-1', [TeacherApplicationController::class, 'register'])
    ->middleware(['throttle:registration', 'idempotent']);

Route::middleware('auth:sanctum')->group(function (): void {
    /*
     | The teachers of the CURRENT workspace, for a picker.
     |
     | ⚠️ DISTINCT FROM `/teachers` ABOVE, which is the PUBLIC cross-workspace
     | listing of publicly-listed profiles. Using that one on a scheduling screen
     | would offer another academy's teachers and hide the operator's own
     | colleagues who are not listed yet.
     */
    Route::get('/manage/teachers', [WorkspaceTeacherController::class, 'index']);

    Route::get('/teacher/application', [TeacherApplicationController::class, 'show']);
    Route::put('/teacher/application/step-2', [TeacherApplicationController::class, 'stepTwo']);
    Route::put('/teacher/application/step-3', [TeacherApplicationController::class, 'stepThree']);
    Route::put('/teacher/application/step-4', [TeacherApplicationController::class, 'stepFour']);
    Route::post('/teacher/application/submit', [TeacherApplicationController::class, 'submit'])
        ->middleware('idempotent');

    // The teacher's own listing. No route parameter: the profile is resolved
    // from the authenticated user, so there is no ownership check to forget.
    // Throttled by account — the uniqueness check makes this an endpoint you
    // could otherwise probe to enumerate which slugs are taken.
    Route::get('/teacher/profile', [TeacherProfileController::class, 'show']);
    // ⚠️ THE EDIT DOOR THAT DID NOT EXIST. Everything step two of the wizard
    // writes was writable once and never again; `/admin` was the only way to
    // correct a teacher's own subjects, stages, languages, qualifications or
    // description. No route parameter, so there is no ownership check to forget.
    Route::put('/teacher/profile', [TeacherProfileController::class, 'update']);
    // ⚠️ THE SECOND COLUMN WITH READERS AND NO EDIT DOOR. `availability_slots`
    // was written ONCE, at application submission, and read ever since by the
    // session generator, the private-session guard and the public profile — so a
    // teacher whose week changed had nowhere at all to say so.
    Route::put('/teacher/availability', [TeacherProfileController::class, 'updateAvailability']);
    Route::put('/teacher/profile/slug', [TeacherProfileController::class, 'updateSlug'])
        ->middleware('throttle:profile-slug');

    Route::get('/admin/teacher-applications', [TeacherReviewController::class, 'index']);
    // Admitting or refusing a teacher decides who may sell on the platform.
    Route::post('/admin/teacher-applications/{uuid}/approve', [TeacherReviewController::class, 'approve'])
        ->middleware('2fa.required');
    Route::post('/admin/teacher-applications/{uuid}/reject', [TeacherReviewController::class, 'reject'])
        ->middleware('2fa.required');
    Route::post('/admin/teacher-applications/{uuid}/request-changes', [TeacherReviewController::class, 'requestChanges']);
    Route::post('/admin/teachers/{uuid}/suspend', [TeacherReviewController::class, 'suspend']);
    Route::post('/admin/teachers/{uuid}/reinstate', [TeacherReviewController::class, 'reinstate']);

    Route::put('/workspace/marketplace-participation', [TeacherReviewController::class, 'setParticipation']);

    // Reviews and complaints. The teacher uuid is a plain string here too — the
    // controller resolves it through the same public Action, so an unlisted
    // profile is no more reviewable than it is viewable.
    Route::post('/teachers/{uuid}/reviews', [ReviewController::class, 'store']);
    // What the form should offer, read from the SAME predicate `store()` refuses
    // with — see `ReadReviewEligibility`. A read of the caller's own standing, so
    // no permission and no extra limiter beyond the group's.
    Route::get('/teachers/{uuid}/reviews/eligibility', [ReviewController::class, 'eligibility']);
    Route::delete('/admin/reviews/{uuid}', [ReviewController::class, 'moderate']);
    Route::post('/admin/complaints/{uuid}/confirm', [ReviewController::class, 'confirmComplaint']);
    Route::post('/admin/complaints/{uuid}/dismiss', [ReviewController::class, 'dismissComplaint']);
});
