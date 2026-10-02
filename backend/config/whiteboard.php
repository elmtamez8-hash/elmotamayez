<?php

declare(strict_types=1);

/*
| Spec 039 — the teacher's whiteboard.
|
| Defaults only: every number here is a `platform_settings` row the operator edits
| from the panel («السبّورة» in ManagePlatformSettings), read through
| `WhiteboardSettings`.
*/
return [
    // One page's scene document. A scene with no images is a few KB; freehand
    // strokes carry their pressures, so a dense page reaches hundreds of KB.
    'max_scene_bytes' => 2 * 1024 * 1024,

    // Every page of one board together — `GET /boards/{board}` returns them all.
    'max_board_bytes' => 50 * 1024 * 1024,

    'max_pages_per_board' => 300,

    // Story 4 — a PDF turned into pages (owner, 2026-10-02: PDF and pictures
    // only; no Office conversion service). One page per PDF page, as a picture.
    'import_max_bytes' => 50 * 1024 * 1024,
    'import_max_pages' => 100,
    // Where `pdfinfo` and `pdftoppm` (poppler-utils) live; empty = on PATH.
    'poppler_path' => env('WHITEBOARD_POPPLER_PATH', ''),
];
