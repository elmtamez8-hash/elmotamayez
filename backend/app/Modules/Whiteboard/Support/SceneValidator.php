<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Models\Board;

/**
 * What a page document may contain before it is stored (contracts/api.md, FR-028).
 *
 *  - no inline file bytes (`dataURL`): files are referenced by id, so a later
 *    collaboration document carries no bytes and a scene stays small;
 *  - no `embeddable` / `iframe` element: an embed is a third-party page inside the
 *    tab the class watches, and Excalidraw would load it for every reader;
 *  - `fileIds` are a template id (generated in the browser, never stored) or a
 *    READY png/jpeg THIS board owns — never another board's file, never a PDF;
 *  - a link is http(s) or a site-relative path. `javascript:`, `data:` and the
 *    scheme-relative `//host` are refused. Anchored at both ends with `\z` and `D`,
 *    because PHP's `$` also matches before a trailing newline.
 *
 * The page is stored as the TEXT that arrived; this only reads it.
 */
final class SceneValidator
{
    private const TEMPLATE = '/^template:[a-z-]+:v\d+\z/D';

    // A browser reads `\` in a URL as `/`, so `/\evil.example` is another host:
    // no backslash anywhere in a site path (`\x5c`, spelled so no escaping layer
    // can eat it).
    private const LINK = '#^(?:https?://\S+|/(?![/\x5c])[^\s\x5c]*)\z#iD';

    private const REFUSED_TYPES = ['embeddable', 'iframe'];

    private const FILE_MIMES = ['image/png', 'image/jpeg'];

    /** @throws WhiteboardRefusal */
    public function validate(Board $board, string $scene): void
    {
        if (str_contains($scene, '"dataURL"')) {
            throw new WhiteboardRefusal('inline_file');
        }

        try {
            $document = json_decode($scene, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WhiteboardRefusal('bad_element');
        }

        if (! is_array($document) || ! is_array($document['elements'] ?? null) || ! is_array($document['fileIds'] ?? [])) {
            throw new WhiteboardRefusal('bad_element');
        }

        $fileIds = array_values(array_filter($document['fileIds'] ?? [], 'is_string'));
        $referenced = [];

        foreach ($document['elements'] as $element) {
            if (! is_array($element) || ! is_string($element['type'] ?? null)) {
                throw new WhiteboardRefusal('bad_element');
            }

            if (in_array($element['type'], self::REFUSED_TYPES, true)) {
                throw new WhiteboardRefusal('bad_element');
            }

            $link = $element['link'] ?? null;
            if ($link !== null && $link !== '' && (! is_string($link) || preg_match(self::LINK, $link) !== 1)) {
                throw new WhiteboardRefusal('bad_link');
            }

            if ($element['type'] === 'image' && is_string($element['fileId'] ?? null)) {
                $referenced[] = $element['fileId'];
            }
        }

        // Every image names a file the document declares …
        if (array_diff($referenced, $fileIds) !== []) {
            throw new WhiteboardRefusal('unknown_file');
        }

        // … and every declared file is a template or a ready picture of this board.
        $assetIds = array_values(array_filter($fileIds, fn (string $id): bool => preg_match(self::TEMPLATE, $id) !== 1));

        if ($assetIds === []) {
            return;
        }

        $owned = MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('owner_type', Board::class)
            ->where('owner_id', $board->getKey())
            ->where('status', MediaAssetStatus::Ready)
            ->whereIn('mime_type', self::FILE_MIMES)
            ->whereIn('uuid', $assetIds)
            ->count();

        if ($owned !== count(array_unique($assetIds))) {
            throw new WhiteboardRefusal('unknown_file');
        }
    }

    /**
     * A shape shared to the academy's library: the page's element rules, and no
     * picture.
     *
     * @param  array<mixed>  $elements
     *
     * @throws WhiteboardRefusal
     */
    public function validateLibraryElements(array $elements): void
    {
        // The page's own reading: what a page would refuse once placed on it is refused here
        // — deeper than 64 levels, or `dataURL` anywhere (caught in review: a deep shape also
        // failed every later list of the academy's library with a 500).
        $json = json_encode($elements, JSON_THROW_ON_ERROR);
        if (str_contains($json, '"dataURL"')) {
            throw new WhiteboardRefusal('inline_file');
        }
        try {
            json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WhiteboardRefusal('bad_element');
        }

        foreach ($elements as $element) {
            if (! is_array($element) || ! is_string($element['type'] ?? null) || in_array($element['type'], self::REFUSED_TYPES, true)) {
                throw new WhiteboardRefusal('bad_element');
            }

            $link = $element['link'] ?? null;
            if ($link !== null && $link !== '' && (! is_string($link) || preg_match(self::LINK, $link) !== 1)) {
                throw new WhiteboardRefusal('bad_link');
            }

            // No picture at all: an uploaded one belongs to its board, and a template's picture is
            // drawn by the board that placed it — a colleague's board showed an empty box.
            if ($element['type'] === 'image') {
                throw new WhiteboardRefusal('unknown_file');
            }
        }
    }
}
