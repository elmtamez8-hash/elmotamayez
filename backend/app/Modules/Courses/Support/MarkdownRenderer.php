<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders teacher-authored text, safely, without a sanitiser.
 *
 * The threat is ordinary: a teacher's text is shown to their students, so a
 * `<script>` in a lesson body executes in the reader's session. The usual
 * answer is an HTML purifier — a new dependency plus a tag allowlist somebody
 * has to maintain, and hand-rolling one is the classic mistake in exactly this
 * position.
 *
 * Storing Markdown removes the question instead of answering it. With
 * `html_input: strip` the allowlist IS the Markdown feature set: there is no
 * tag list to keep current, because raw HTML never survives the parse. And
 * league/commonmark ships with the framework, so nothing was added to
 * composer.json.
 *
 * The rendered HTML is NOT stored. `content` holds the source the editor loads;
 * the HTML is derived per response. A stored second copy of the same words
 * drifts from the source at the first typo fix — the same reason the transcript
 * in 004 is derived from the caption file rather than kept beside it.
 */
final class MarkdownRenderer
{
    private static ?MarkdownConverter $converter = null;

    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return (string) self::converter()->convert($markdown);
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter instanceof MarkdownConverter) {
            return self::$converter;
        }

        $environment = new Environment([
            // Raw HTML in the source is dropped, not escaped and not passed
            // through. This is the whole security decision, in one option.
            'html_input' => 'strip',
            // Blocks javascript: and data: in links and images. Markdown link
            // syntax is not a safe harbour by itself.
            'allow_unsafe_links' => false,
            'max_nesting_level' => 32,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);

        return self::$converter = new MarkdownConverter($environment);
    }
}
