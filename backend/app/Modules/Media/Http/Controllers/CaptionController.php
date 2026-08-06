<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Actions\AttachCaption;
use App\Modules\Media\Actions\DeleteCaption;
use App\Modules\Media\Enums\CaptionKind;
use App\Modules\Media\Http\Requests\StoreCaptionRequest;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCaption;
use App\Modules\Media\Support\PlaybackGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class CaptionController extends Controller
{
    public function store(StoreCaptionRequest $request, MediaAsset $asset, AttachCaption $action): JsonResponse
    {
        // Same permission as replacing the video itself: a caption track is part
        // of the lesson's content, not a comment on it.
        $this->authorize('delete', $asset);

        $caption = $action->handle(
            $asset,
            $request->file('file'),
            (string) $request->validated('language', 'ar'),
            CaptionKind::from((string) $request->validated('kind', 'captions')),
            (bool) $request->validated('is_default', true),
        );

        return response()->json([
            'uuid' => $caption->uuid,
            'language' => $caption->language,
            'kind' => $caption->kind->value,
            'is_default' => $caption->is_default,
        ], 201);
    }

    public function destroy(MediaCaption $caption, DeleteCaption $action): JsonResponse
    {
        $this->authorize('delete', $caption->asset);

        $action->handle($caption);

        return response()->json(null, 204);
    }

    /**
     * The file a `<track>` element fetches.
     *
     * Unauthenticated for the same reason the stream is — a `<track>` sends no
     * Authorization header — and guarded the same way: through the grant, which
     * expires. A captions file on a permanent public URL would be the lesson's
     * entire script, permanently linkable.
     */
    public function show(string $grant, string $caption): Response
    {
        $model = PlaybackGuard::resolve($grant);

        abort_if($model === null, 403, 'لا تملك صلاحية لهذا الإجراء.');

        $track = MediaCaption::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $caption)
            ->where('media_asset_id', $model->media_asset_id)
            ->first();

        abort_if($track === null, 404);

        $disk = Storage::disk((string) config('media.disk'));

        abort_unless($disk->exists($track->storage_path), 404);

        return response((string) $disk->get($track->storage_path), 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
