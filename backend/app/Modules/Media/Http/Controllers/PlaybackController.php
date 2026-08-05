<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Media\Actions\IssuePlaybackGrant;
use App\Modules\Media\Actions\RenewPlaybackGrant;
use App\Modules\Media\Contracts\VideoProviderInterface;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Http\Resources\PlaybackGrantResource;
use App\Modules\Media\Providers\LocalVideoProvider;
use App\Modules\Media\Support\PlaybackGuard;
use App\Shared\Scopes\WorkspaceScope;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaybackController extends Controller
{
    public function issue(Request $request, string $lesson, IssuePlaybackGrant $action): JsonResponse
    {
        $user = $this->currentUser($request);

        $model = Lesson::query()->withoutWorkspaceScope()->where('uuid', $lesson)->first();

        // 404 rather than 403 for a lesson the viewer may not see at all: that a
        // particular lesson exists is itself information.
        abort_if($model === null, 404);

        $session = $this->currentSession($request);
        abort_if($session === null, 401);

        try {
            $grant = $action->handle(
                $model,
                $user,
                $session,
                $request->ip() === null ? null : hash('sha256', $request->ip()),
            );
        } catch (DomainException $e) {
            // Entitled, but the video is not ready. Distinct from a refusal so
            // the screen can say "قيد التجهيز" instead of showing an error.
            return response()->json([
                'message' => 'الفيديو قيد التجهيز، حاول بعد قليل.',
                'status' => $e->getMessage(),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        // Merged by hand rather than with `->additional()`: additional data is
        // attached by the resource's *response*, and `->resolve()` drops it, so
        // the resume position never reached the player and FR-036 was dead on
        // arrival. Going through the response instead would answer 201 here,
        // because the grant was just created.
        return response()->json([
            ...PlaybackGrantResource::make($grant)->resolve(),
            'resume_at_seconds' => $this->resumePosition($user->getKey(), (int) $model->getKey()),
        ]);
    }

    /**
     * Serve the bytes, or send the browser to the provider.
     *
     * Deliberately unauthenticated: a <video> element cannot attach a bearer
     * token. The guard is the grant row, re-checked here on every range request —
     * which is what stops playback mid-file when the session is ended elsewhere
     * or the watermark stops renewing.
     */
    public function stream(Request $request, string $grant, VideoProviderInterface $provider): Response|StreamedResponse
    {
        $model = PlaybackGuard::resolve($grant);

        // One refusal for every failure mode, revealing nothing about the asset,
        // its filename or where it is stored.
        abort_if($model === null, 403, 'لا تملك صلاحية لهذا الإجراء.');

        $model->forceFill(['last_seen_at' => now()])->saveQuietly();

        $manifest = $provider->manifest(new PlaybackContext(
            asset: $model->asset,
            grant: $model,
            viewerIpHash: $request->ip() === null ? null : hash('sha256', $request->ip()),
        ));

        if ($manifest->isRedirect) {
            // A commercial provider signs its own URL and the browser pulls
            // segments straight from the CDN — no video passes through PHP, and
            // no provider identifier appears in any JSON we emit.
            return redirect()->away($manifest->url, 302);
        }

        // Only the local provider serves bytes itself, and only it knows where
        // they are — hence the concrete type here rather than the interface.
        abort_unless($provider instanceof LocalVideoProvider, 500);

        $path = $model->asset->provider_asset_id;
        abort_if($path === null, 404);

        $disk = $provider->disk();
        abort_unless($disk->exists($path), 404);

        // Range support is not a detail: it is what makes each further chunk a
        // fresh trip through the guard above.
        return $disk->response($path, null, [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, private',
            'Content-Type' => $model->asset->mime_type ?? 'video/mp4',
        ]);
    }

    /**
     * Extend the grant, save the position, and surface a dead session.
     *
     * Called by the watermark component once a minute. Three jobs on one call
     * because it already happens at that cadence, and a second endpoint on the
     * same clock would double the traffic for nothing.
     */
    public function renew(Request $request, string $grant, RenewPlaybackGrant $action): JsonResponse
    {
        $model = PlaybackGuard::resolve($grant);

        abort_if($model === null, 403, 'انتهت صلاحية التشغيل.');
        abort_if($model->user_id !== $this->currentUser($request)->getKey(), 403);

        $position = $request->integer('position_seconds');

        try {
            $model = $action->handle($model, $position > 0 ? $position : null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'expires_at' => $model->expires_at->toIso8601String(),
            'manifest_url' => url("/api/v1/playback/{$model->uuid}/stream"),
        ]);
    }

    private function currentSession(Request $request): ?AuthSession
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        if ($tokenId === null) {
            return null;
        }

        return AuthSession::query()->active()->where('token_id', $tokenId)->first();
    }

    private function resumePosition(int $userId, int $lessonId): int
    {
        $progress = LessonProgress::withoutWorkspaceScope()
            ->where('lesson_id', $lessonId)
            ->whereHas('enrollment', fn (Builder $query) => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('student_user_id', $userId))
            ->first();

        return (int) ($progress->last_position_seconds ?? 0);
    }
}
