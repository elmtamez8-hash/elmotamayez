<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

/**
 * Whether a WebM or MP4 file holds sound and nothing else.
 *
 * ⚠️ THE MAGIC BYTES NAME THE CONTAINER, NOT WHAT IS IN IT. libmagic reports every
 * WebM file as `video/webm` and most MP4s as `video/mp4`, whatever their tracks —
 * and those two containers are exactly what a browser's `MediaRecorder` produces
 * for a voice note (Chrome and Firefox record `audio/webm;codecs=opus`, Safari
 * records `audio/mp4`). So every voice note sent from a phone was read as a
 * VIDEO, refused by the audio list, and marked `failed` — and the sender was then
 * told «لم يكتمل رفع المرفق بعد» about a file that had arrived whole. Measured on
 * production on 2026-09-28 (media asset 11).
 *
 * ⚠️ THE ANSWER IS READ FROM THE TRACK HEADERS, AND IT FAILS CLOSED. A recorder
 * has to write its track list before the first frame (a live stream cannot seek
 * back to add it), so both containers carry it inside the first few hundred
 * bytes. Only a file that declares at least one audio track and NO video track is
 * called audio; anything else — a real video, a header this cannot read — keeps
 * the container's own answer and is refused by the audio list as before.
 *
 * Not `ffprobe`: a process per upload and an image dependency to answer one
 * question the header already answers, and a development machine without it
 * would refuse every voice note.
 */
final class AudioContainer
{
    /** The containers a recorder writes, as libmagic names them. */
    private const WEBM = ['video/webm', 'video/x-matroska', 'audio/webm', 'audio/x-matroska'];

    private const MP4 = ['video/mp4', 'audio/mp4', 'audio/x-m4a', 'video/quicktime'];

    /** Matroska `Tracks` element id. */
    private const EBML_TRACKS = "\x16\x54\xAE\x6B";

    /** Matroska `TrackType` element id. */
    private const EBML_TRACK_TYPE = "\x83";

    private const TRACK_VIDEO = 1;

    private const TRACK_AUDIO = 2;

    /**
     * The audio type this file really is, or null when it is not provably sound
     * alone.
     *
     * @param  string  $sniffed  what libmagic said about the same bytes
     */
    public static function audioOnlyMime(string $head, string $sniffed): ?string
    {
        if (in_array($sniffed, self::WEBM, true)) {
            return self::webmIsAudioOnly($head) ? 'audio/webm' : null;
        }

        if (in_array($sniffed, self::MP4, true)) {
            return self::mp4IsAudioOnly($head) ? 'audio/mp4' : null;
        }

        return null;
    }

    /**
     * Every `TrackType` inside the `Tracks` element is audio.
     *
     * Scoped to the `Tracks` element rather than the whole head: the same byte
     * pattern can occur inside a codec's private data or a cluster of sound.
     */
    private static function webmIsAudioOnly(string $head): bool
    {
        $start = strpos($head, self::EBML_TRACKS);

        if ($start === false) {
            return false;
        }

        $offset = $start + strlen(self::EBML_TRACKS);
        $size = self::readVint($head, $offset);

        if ($size === null) {
            return false;
        }

        [$length, $width] = $size;
        $body = $length === null
            // «Unknown size» — a live writer's escape hatch. Read to the end of
            // the head; the first cluster follows, and a stray match there can
            // only make the answer stricter.
            ? substr($head, $offset + $width)
            : substr($head, $offset + $width, $length);

        $types = [];
        $cursor = 0;

        while (($found = strpos($body, self::EBML_TRACK_TYPE, $cursor)) !== false) {
            $cursor = $found + 1;
            $size = self::readVint($body, $found + 1);

            // TrackType is a one-byte unsigned integer: its size vint is 0x81.
            if ($size === null || $size[0] !== 1 || ! isset($body[$found + 1 + $size[1]])) {
                continue;
            }

            $types[] = ord($body[$found + 1 + $size[1]]);
        }

        return in_array(self::TRACK_AUDIO, $types, true) && ! in_array(self::TRACK_VIDEO, $types, true);
    }

    /**
     * At least one `hdlr` box names a sound track and none names a video track.
     *
     * The handler type sits twelve bytes after the box's type field (version and
     * flags, then `pre_defined`). Other handlers — `mdir` for iTunes metadata —
     * are neither and are ignored.
     */
    private static function mp4IsAudioOnly(string $head): bool
    {
        $handlers = [];
        $cursor = 0;

        while (($found = strpos($head, 'hdlr', $cursor)) !== false) {
            $cursor = $found + 4;
            $handler = substr($head, $found + 12, 4);

            if (strlen($handler) === 4) {
                $handlers[] = $handler;
            }
        }

        return in_array('soun', $handlers, true) && ! in_array('vide', $handlers, true);
    }

    /**
     * An EBML variable-length integer: [value or null for «unknown», width].
     *
     * @return array{0: int|null, 1: int}|null
     */
    private static function readVint(string $bytes, int $offset): ?array
    {
        if (! isset($bytes[$offset])) {
            return null;
        }

        $first = ord($bytes[$offset]);

        for ($width = 1; $width <= 8; $width++) {
            if (($first & (0x80 >> ($width - 1))) !== 0) {
                break;
            }
        }

        if ($width > 8 || $offset + $width > strlen($bytes)) {
            return null;
        }

        $value = $first & ((0x80 >> ($width - 1)) - 1);
        $allOnes = $value === ((0x80 >> ($width - 1)) - 1);

        for ($i = 1; $i < $width; $i++) {
            $byte = ord($bytes[$offset + $i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && $byte === 0xFF;
        }

        return [$allOnes ? null : $value, $width];
    }
}
