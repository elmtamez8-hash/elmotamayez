<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Marketplace\Actions\Public\ReadCourseTrial;
use App\Modules\Marketplace\Http\Resources\PublicCourseTrialResource;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The guest door to a course's «حصة تجريبية» (spec 040 · FR-009 – FR-013).
 *
 * ⚠️ SEPARATE FROM STUDENT PLAYBACK, ON PURPOSE. `/playback/{grant}/...` rests on
 * a saved grant tied to an account and a sign-in session; this door writes no
 * row, fires no event, records no progress, and hands out a signed CDN link
 * that dies in `media.trial_link_ttl_seconds`. Nothing here touches
 * `IssuePlaybackGrant`, `PlaybackGuard` or the watermark (FR-014).
 *
 * `Cache-Control: no-store` on both answers: a cached descriptor or 302 would
 * outlive the teacher withdrawing the trial.
 */
final class PublicCourseTrialController extends Controller
{
    public function show(string $courseKey, ReadCourseTrial $action): JsonResponse
    {
        [$course, $lesson] = $action->handle($courseKey);

        return response()
            ->json(['data' => (new PublicCourseTrialResource($course, $lesson))->resolve()])
            ->header('Cache-Control', 'no-store');
    }

    public function stream(string $courseKey, ReadCourseTrial $action, MediaProviderResolver $providers): RedirectResponse
    {
        [, $lesson] = $action->handle($courseKey);

        $asset = $lesson->type === LessonType::Video->value ? $lesson->mediaAsset : null;

        if (! $asset instanceof MediaAsset) {
            throw new NotFoundHttpException('غير متاح');
        }

        $ttl = max(60, (int) PlatformSettings::get('media.trial_link_ttl_seconds'));

        try {
            $manifest = $providers->for($asset)->manifest(new PlaybackContext(
                asset: $asset,
                expiresAt: CarbonImmutable::now()->addSeconds($ttl),
            ));
        } catch (Throwable) {
            // An unknown provider, or one that needs a grant (the local one):
            // the same 404 as every other refusal, never a 500 that says more.
            throw new NotFoundHttpException('غير متاح');
        }

        // Only a provider that redirects to a signed URL may serve a guest; the
        // rule already restricts trials to those (`media.trial_providers`).
        if (! $manifest->isRedirect) {
            throw new NotFoundHttpException('غير متاح');
        }

        return redirect()->away($manifest->url)->header('Cache-Control', 'no-store');
    }
}
