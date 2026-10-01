<?php

declare(strict_types=1);

use App\Modules\Courses\Support\MarkdownRenderer;

/*
| `toPlainText()` is what a place that shows TEXT reads (the notification bell, the
| search index): the words of the Markdown, never its syntax, and agreeing with
| `toHtml()` about what the source says.
*/

it('keeps the words and drops the syntax', function (): void {
    $markdown = "## عنوان\n\nنص **غامق** و _مائل_ و [رابط](https://example.com).\n\n- أول\n- تاني";

    expect(MarkdownRenderer::toPlainText($markdown))
        ->toContain('عنوان')
        ->toContain('نص غامق و مائل و رابط.')
        ->toContain('أول')
        ->not->toContain('**')
        ->not->toContain('##')
        ->not->toContain('](');
});

it('reads an escaped character as the character, and drops raw HTML tags as the renderer does', function (): void {
    // `html_input: strip` drops the TAGS and keeps their text — the same as the page shows.
    expect(MarkdownRenderer::toPlainText('3 \* 4 &amp; <script>x</script>5'))->toBe('3 * 4 & x5')
        ->and(MarkdownRenderer::toPlainText(null))->toBe('')
        ->and(MarkdownRenderer::toPlainText('   '))->toBe('');
});
