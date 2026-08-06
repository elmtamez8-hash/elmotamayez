<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use DomainException;

/**
 * Just enough WebVTT to refuse a broken file and to read the words back.
 *
 * Deliberately not a full parser: regions, positioning and styling are passed
 * through untouched because the browser renders them and we never do. What is
 * checked is what silently produces a track that shows nothing — a missing
 * signature, or cues with no timing line.
 *
 * The transcript (FR-034) is derived here rather than stored. A transcript
 * column would be a second copy of the same words, and the two drift apart the
 * first time someone fixes a typo in a subtitle.
 */
final class WebVtt
{
    /** `00:00:01.000 --> 00:00:04.000` with optional hours and trailing settings. */
    private const TIMING = '/^(?:\d{2,}:)?\d{2}:\d{2}\.\d{3}\s*-->\s*(?:\d{2,}:)?\d{2}:\d{2}\.\d{3}/m';

    /**
     * @return array<int, array{start: string, text: string}>
     *
     * @throws DomainException when the file could not produce a working track
     */
    public static function parse(string $content): array
    {
        // A BOM in front of the signature is common from Windows editors and
        // breaks the check for no reason a user could understand.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace("\r\n", "\n", trim($content));

        if (! str_starts_with($content, 'WEBVTT')) {
            throw new DomainException('الملف ليس بصيغة WebVTT. يجب أن يبدأ بسطر WEBVTT.');
        }

        $cues = [];

        foreach (explode("\n\n", $content) as $block) {
            $lines = array_values(array_filter(
                array_map(trim(...), explode("\n", $block)),
                fn (string $line): bool => $line !== '',
            ));

            $timingAt = null;

            foreach ($lines as $index => $line) {
                if (preg_match(self::TIMING, $line) === 1) {
                    $timingAt = $index;

                    break;
                }
            }

            if ($timingAt === null) {
                continue;
            }

            $text = implode(' ', array_slice($lines, $timingAt + 1));

            if ($text === '') {
                continue;
            }

            $cues[] = [
                'start' => trim(explode('-->', $lines[$timingAt])[0]),
                // Inline tags like <v Speaker> belong to the renderer, not to the
                // words someone reads or searches.
                'text' => trim(strip_tags($text)),
            ];
        }

        if ($cues === []) {
            throw new DomainException('لا يحتوي الملف على أي مقطع نصّي صالح.');
        }

        return $cues;
    }

    /** The whole thing as prose, derived — never stored. */
    public static function transcript(string $content): string
    {
        return implode(' ', array_column(self::parse($content), 'text'));
    }
}
