<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Whiteboard\Actions\CompleteBoardFile;
use App\Modules\Whiteboard\Actions\RequestBoardFile;
use App\Modules\Whiteboard\Http\Requests\RequestBoardFileRequest;
use App\Modules\Whiteboard\Models\Board;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A board's pictures: reserve an upload, settle it, read the bytes back. The file
 * is always found THROUGH the board (`CompleteBoardFile::find`).
 */
class BoardFileController extends Controller
{
    public function store(RequestBoardFileRequest $request, Board $board, RequestBoardFile $action): JsonResponse
    {
        $this->authorize('update', $board);

        $result = $action->handle(
            $this->currentUser($request),
            $board,
            $request->tab(),
            (string) $request->validated('filename'),
            (int) $request->validated('size'),
        );

        return response()->json([
            'file' => ['uuid' => $result['asset']->uuid],
            'upload' => [
                'url' => $result['ticket']->url,
                'method' => $result['ticket']->method,
                'headers' => $result['ticket']->headers,
            ],
        ], 201);
    }

    public function complete(Board $board, string $file, CompleteBoardFile $action): JsonResponse
    {
        $this->authorize('update', $board);

        $settled = $action->handle($board, $file);

        return response()->json(['uuid' => $settled->uuid, 'status' => $settled->status->value]);
    }

    /**
     * The bytes of a READY picture only — an imported original (a PDF) or an
     * upload still pending reads as missing, exactly like another board's file.
     */
    public function show(Board $board, string $file, MediaProviderResolver $providers): StreamedResponse
    {
        $this->authorize('view', $board);

        $asset = CompleteBoardFile::find($board, $file);
        $path = $asset?->provider_asset_id;
        abort_if(
            $asset === null || $path === null
            || $asset->status !== MediaAssetStatus::Ready
            || ! in_array($asset->mime_type, CompleteBoardFile::MIMES, true),
            404,
        );

        $provider = $providers->for($asset);
        abort_unless($provider instanceof LocalMediaProvider, 500);

        $disk = $provider->disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => (string) $asset->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            // A day: the bytes never change under one uuid, but a deleted file
            // should not linger in a shared school computer's cache for a year.
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }
}
