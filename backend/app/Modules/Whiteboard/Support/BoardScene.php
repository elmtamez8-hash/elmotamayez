<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

/**
 * The page document the server writes itself: a new page's blank scene.
 *
 * Shape (data-model.md): `{ v, elements[], appState: {viewBackgroundColor}, fileIds[] }`.
 * The first element is the page's 16:9 frame, id `frame:{pageUuid}` — one id PER
 * PAGE, because a shared id let undo apply one page's frame edits to another
 * (measured in the phase 0 lab). The browser owns every other element.
 */
final class BoardScene
{
    public const VERSION = 1;

    public const PAGE_WIDTH = 1920;

    public const PAGE_HEIGHT = 1080;

    /** The canvas colour of each background, as `lib/whiteboard/page-model.ts` has it. */
    public const CANVAS = [
        'white' => '#ffffff',
        'blackboard' => '#1c2024',
        'greenboard' => '#1e3d2f',
    ];

    public static function blank(string $pageUuid, string $background = 'white'): string
    {
        return (string) json_encode([
            'v' => self::VERSION,
            'elements' => [self::frame($pageUuid)],
            'appState' => ['viewBackgroundColor' => self::CANVAS[$background] ?? self::CANVAS['white']],
            'fileIds' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A page of an imported PDF (story 4): its picture, 1920 wide, locked under
     * everything drawn on it, and a frame as many screens tall as the picture
     * needs (the page grows downward — a portrait A4 is three screens).
     */
    public static function withPicture(string $pageUuid, string $background, string $fileId, int $height, string $importUuid, int $pageNumber): string
    {
        $screens = max(1, (int) ceil($height / self::PAGE_HEIGHT));
        $frame = self::frame($pageUuid);
        $frame['height'] = $screens * self::PAGE_HEIGHT;

        return (string) json_encode([
            'v' => self::VERSION,
            'elements' => [
                [
                    'id' => 'doc:'.$pageUuid,
                    'type' => 'image',
                    'x' => 0,
                    'y' => 0,
                    'width' => self::PAGE_WIDTH,
                    'height' => $height,
                    'angle' => 0,
                    'fileId' => $fileId,
                    'status' => 'saved',
                    'scale' => [1, 1],
                    'locked' => true,
                    'frameId' => 'frame:'.$pageUuid,
                    'isDeleted' => false,
                    'version' => 1,
                    'versionNonce' => 1,
                    'customData' => ['kind' => 'doc-background', 'v' => 1, 'importUuid' => $importUuid, 'page' => $pageNumber],
                ],
                $frame,
            ],
            'appState' => ['viewBackgroundColor' => self::CANVAS[$background] ?? self::CANVAS['white']],
            'fileIds' => [$fileId],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A locked frame element as Excalidraw 0.18 writes one. Excalidraw's
     * `restoreElements` fills any field it does not find, so only what the page
     * model depends on is spelled out.
     *
     * @return array<string, mixed>
     */
    public static function frame(string $pageUuid): array
    {
        return [
            'id' => 'frame:'.$pageUuid,
            'type' => 'frame',
            'x' => 0,
            'y' => 0,
            'width' => self::PAGE_WIDTH,
            'height' => self::PAGE_HEIGHT,
            'angle' => 0,
            'name' => '',
            'locked' => true,
            'isDeleted' => false,
            'version' => 1,
            'versionNonce' => 1,
            'customData' => ['kind' => 'frame', 'v' => 1],
        ];
    }
}
