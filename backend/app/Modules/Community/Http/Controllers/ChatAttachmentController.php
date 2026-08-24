<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\RequestChatAttachment;
use App\Modules\Community\Http\Requests\RequestChatAttachmentRequest;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploading a chat attachment, and serving one back.
 *
 * ⚠️ THE SERVE ROUTE IS `signed`, NOT `auth:sanctum`, AND THAT IS FORCED BY THE
 * BROWSER. An `<img>` and an `<audio>` send no `Authorization` header — the same
 * fact that put captions behind a grant URL in 019 — so a bearer-guarded route
 * can only ever be fetched by JavaScript, which means loading every picture into
 * memory as a blob before it can be shown.
 *
 * What replaces the token is a signature minted INSIDE an authorised response:
 * `MessageResource` only renders for a reader `ConversationPolicy::view()` has
 * already admitted, and the link it embeds lasts fifteen minutes. So the link is
 * a short-lived bearer capability — exactly like the presigned URLs this product
 * already hands the provider — and it is documented as one rather than dressed up
 * as an authorisation check.
 *
 * ⚠️ AND A HIDDEN MESSAGE SERVES NOTHING. `hidden_at` is what moderation writes,
 * and a picture that kept streaming after its message was hidden would make the
 * whole control cosmetic — the bytes are the message.
 */
class ChatAttachmentController extends Controller
{
    /** Somewhere to put the bytes, before they become a message. */
    public function store(
        RequestChatAttachmentRequest $request,
        string $conversation,
        RequestChatAttachment $action,
    ): JsonResponse {
        // Resolved inside the request, never by route-model binding:
        // `WorkspaceScope` is inert for a student, so an implicit binding would
        // resolve any workspace's conversation before a policy ran.
        $thread = Conversation::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $conversation)
            ->firstOrFail();

        $result = $action->handle(
            $this->currentUser($request),
            $thread,
            (string) $request->validated('kind'),
            (string) $request->validated('filename'),
            $request->validated('size_bytes') === null ? null : (int) $request->validated('size_bytes'),
            $request->validated('duration_seconds') === null ? null : (int) $request->validated('duration_seconds'),
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

    /** The bytes, behind a signature the reader was given while authorised. */
    public function show(string $message, MediaProviderResolver $providers): StreamedResponse
    {
        $row = Message::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $message)
            ->whereNull('hidden_at')
            ->with('mediaAsset')
            ->first();

        // One refusal for every failure mode — no such message, hidden, or no
        // attachment — so the URL reveals nothing about which it was.
        abort_if($row === null || ! $row->mediaAsset instanceof MediaAsset, 404);

        $asset = $row->mediaAsset;

        // The asset's OWN provider, never the configured one: an attachment sent
        // before a provider switch lives where it was put.
        $provider = $providers->for($asset);

        abort_unless($provider instanceof LocalMediaProvider, 500);

        $path = $asset->provider_asset_id;
        abort_if($path === null, 404);

        $disk = $provider->disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            null,
            [
                // Range support is what lets a voice note be scrubbed rather than
                // downloaded whole before it can play.
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'no-store, private',
                'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
            ],
            // Always inline. `is_downloadable` is false on every chat attachment,
            // and the disposition is decided here rather than by whichever button
            // the client chose to render.
            'inline',
        );
    }
}
