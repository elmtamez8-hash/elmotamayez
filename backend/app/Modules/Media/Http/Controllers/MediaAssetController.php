<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Actions\RequestUploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Http\Requests\StoreMediaAssetRequest;
use App\Modules\Media\Http\Resources\MediaAssetResource;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalVideoProvider;
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
            );
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
    public function receiveUpload(Request $request, string $token, LocalVideoProvider $provider): JsonResponse
    {
        $asset = MediaAsset::query()->withoutWorkspaceScope()->where('uuid', $token)->first();

        abort_if($asset === null, 404);

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
