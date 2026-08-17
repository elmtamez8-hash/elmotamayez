<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Actions\RequestUploadTicket;
use App\Modules\Media\Actions\SetAssetDisposition;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Http\Requests\StoreMediaAssetRequest;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaAssetController extends Controller
{
    public function store(StoreMediaAssetRequest $request, Lesson $lesson, RequestUploadTicket $action): JsonResponse
    {
        $this->authorize('create', [MediaAsset::class, $lesson]);

        try {
            $result = $action->handle(
                $lesson,
                $request->validated('original_filename'),
                $request->validated('size_bytes'),
                $request->validated('duration_seconds'),
                $request->kind(),
                $request->role(),
            );
        } catch (ContentLockedException $e) {
            // Rethrown rather than folded into the 422 below. A locked item is
            // not a malformed request: it answers 423 and carries the way out
            // (`alternative`), and catching `DomainException` first — which this
            // extends — turned that into a validation error on `original_filename`,
            // where the client shows it under the filename box as if the teacher
            // had typed something wrong.
            throw $e;
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['original_filename' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'asset' => MediaAssetResource::make($result['asset']),
            'upload' => [
                'url' => $result['ticket']->url,
                'method' => $result['ticket']->method,
                'headers' => $result['ticket']->headers,
                'expires_at' => $result['ticket']->expiresAt->toIso8601String(),
            ],
        ], 201);
    }

    public function show(MediaAsset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json(MediaAssetResource::make($asset));
    }

    public function complete(MediaAsset $asset, CompleteMediaUpload $action): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json(MediaAssetResource::make($action->handle($asset)));
    }

    /**
     * Flip view-only / allow-download.
     *
     * Not part of the upload payload: the teacher changes their mind about a
     * file that is already there, and making them re-upload to change one
     * boolean is how a switch stops being used.
     */
    public function disposition(
        Request $request,
        MediaAsset $asset,
        SetAssetDisposition $action,
    ): JsonResponse {
        $this->authorize('update', $asset);

        $validated = $request->validate(['is_downloadable' => ['required', 'boolean']]);

        return response()->json(
            MediaAssetResource::make($action->handle($asset, (bool) $validated['is_downloadable'])),
        );
    }

    public function destroy(MediaAsset $asset, DeleteMediaAsset $action): JsonResponse
    {
        $this->authorize('delete', $asset);

        $action->handle($asset);

        return response()->json(null, 204);
    }

    /**
     * The local provider's upload target.
     *
     * Reached by an unguessable, expiring ticket token rather than by bearer
     * auth, because a commercial provider's ticket would point at its own host
     * with the same shape — keeping one upload path in the client whichever
     * provider is configured.
     */
    public function receiveUpload(Request $request, string $token, LocalMediaProvider $provider): JsonResponse
    {
        $asset = MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $token)->first();

        abort_if($asset === null, 404);

        /*
         * ⚠️ THIS ROUTE IS THE LOCAL PROVIDER'S, AND IT USED TO ACCEPT ANY ASSET'S
         * BYTES.
         *
         * The ticket token is the asset's own uuid and this endpoint is
         * unauthenticated by design — the unguessable token is the credential. What
         * was missing is that it never asked whose asset it was: with a commercial
         * provider configured, an asset belonging to THAT provider could be handed
         * bytes here, which wrote a local disk path into `provider_asset_id`.
         * `CompleteMediaUpload` then resolved the commercial provider from the column
         * and asked it about a file that was never created there — so the upload
         * failed with a message naming the wrong cause, and a stray file was left on
         * our disk.
         *
         * Compared against the injected provider's own identifier rather than a
         * literal, and against the COLUMN rather than the config — which is what
         * keeps a pre-flip local asset still in `Pending` uploadable after a switch,
         * the very case the resolver exists for.
         */
        abort_unless($asset->provider === $provider->identifier(), 404);

        // Only an asset still awaiting its bytes accepts them. Without this, the
        // ticket for a finished asset would stay a live write endpoint.
        abort_unless(
            in_array($asset->status, [MediaAssetStatus::Pending, MediaAssetStatus::Uploading], true),
            409,
        );

        $asset->forceFill(['status' => MediaAssetStatus::Uploading])->save();

        $path = $provider->pathFor($asset);
        $provider->disk()->put($path, $request->getContent(true));

        $asset->forceFill([
            'provider_asset_id' => $path,
            'status' => MediaAssetStatus::Processing,
        ])->save();

        return response()->json(MediaAssetResource::make($asset));
    }
}
