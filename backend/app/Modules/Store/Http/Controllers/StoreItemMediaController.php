<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\CompleteStoreFile;
use App\Modules\Store\Actions\RequestStoreFile;
use App\Modules\Store\Actions\SaveStoreCover;
use App\Modules\Store\Http\Resources\ManageStoreItemResource;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * A product's two uploads: the file a digital buyer gets, and the cover every
 * reader sees. Both ask `StoreItemPolicy::update()` — the teacher's own door
 * (a staff route, so `{item}` binds under the workspace scope).
 */
class StoreItemMediaController extends Controller
{
    public function requestFile(Request $request, StoreItem $item, RequestStoreFile $action): JsonResponse
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $action->handle(
            $this->currentUser($request),
            $item,
            (string) $validated['filename'],
            isset($validated['size_bytes']) ? (int) $validated['size_bytes'] : null,
        );

        return response()->json([
            'asset' => ['uuid' => $result['asset']->uuid],
            'upload' => [
                'url' => $result['ticket']->url,
                'method' => $result['ticket']->method,
                'headers' => $result['ticket']->headers,
            ],
        ], 201);
    }

    public function completeFile(StoreItem $item, string $asset, CompleteStoreFile $action): JsonResponse
    {
        $this->authorize('update', $item);

        $settled = $action->handle($item, $asset);

        return response()->json([
            'uuid' => $settled->uuid,
            'status' => $settled->status->value,
        ]);
    }

    public function cover(Request $request, StoreItem $item, SaveStoreCover $action): ManageStoreItemResource
    {
        $this->authorize('update', $item);

        $request->validate([
            // Bounded in pixels as well as bytes: a small file can decode to a
            // huge bitmap, and GD holds the whole of it in memory.
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $file = $request->file('cover');

        $saved = $action->handle($item, $file instanceof UploadedFile ? $file : null);

        return new ManageStoreItemResource($saved->load(['course:id,uuid,title', 'mediaAsset']));
    }
}
