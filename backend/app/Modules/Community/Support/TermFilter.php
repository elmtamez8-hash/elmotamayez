<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Enums\TermPolicy;
use App\Modules\Community\Models\BlockedTerm;

/**
 * What the term list has to say about one message (`FR-020`).
 *
 * ⚠️ WORD BOUNDARIES, NEVER CONTAINMENT. «الممنوعات» contains «ممنوع» and is a
 * different word; a containment filter refuses it, and over-blocking is precisely
 * what teaches a room to spell around the list — after which it stops working for
 * the words it was written for.
 *
 * ⚠️ AND THE BOUNDARY IS `\p{L}` LOOKAROUNDS, NOT `\b`. PCRE defines `\b` through
 * `\w`, which is ASCII unless the pattern is compiled with UCP — and every term in
 * this product is Arabic. On the build this was written against `\b` happens to
 * behave (PHP 8.5 · PCRE2 with `/u`), which is worse than if it did not: a filter
 * that depends on a compile flag can start permitting EVERYTHING silently on a
 * different build, and nothing errors, nothing is logged, and the first anyone
 * knows is a screenshot. `BlockedTermTest` uses Arabic fixtures so the swap back
 * is measured rather than reasoned about.
 */
final class TermFilter
{
    /** What the mask policy leaves behind. */
    private const MASK = '▮▮▮';

    /**
     * @return array{body: string, refused: string|null, review: list<string>}
     *                                                                         refused = the sentence to show the sender
     */
    public function apply(int $workspaceId, string $body): array
    {
        $terms = BlockedTerm::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->get(['term', 'policy']);

        $review = [];

        foreach ($terms as $term) {
            $needle = (string) $term->term;

            if ($needle === '' || ! $this->matches($needle, $body)) {
                continue;
            }

            switch ($term->policy) {
                case TermPolicy::Block:
                    /*
                    | ⚠️ THE REFUSAL NAMES NO TERM. Echoing the word back turns the
                    | endpoint into a probe of the teacher's own list — send a
                    | candidate, read whether it was named — and a list somebody
                    | can enumerate is a list they can spell around exactly.
                    */
                    return [
                        'body' => $body,
                        'refused' => 'رسالتك تحتوي كلمةً غير مسموح بها هنا. أعد صياغتها من فضلك.',
                        'review' => [],
                    ];

                case TermPolicy::Mask:
                    $body = $this->replace($needle, $body);
                    break;

                case TermPolicy::Review:
                    // Delivered, and a human is told. The message is not the
                    // decision; a person makes that.
                    $review[] = $needle;
                    break;
            }
        }

        return ['body' => $body, 'refused' => null, 'review' => $review];
    }

    private function matches(string $term, string $body): bool
    {
        return preg_match($this->pattern($term), $body) === 1;
    }

    private function replace(string $term, string $body): string
    {
        $replaced = preg_replace($this->pattern($term), self::MASK, $body);

        return is_string($replaced) ? $replaced : $body;
    }

    /**
     * A case-insensitive, boundary-aware pattern for one term.
     *
     * The lookarounds stand in for `\b` in both directions: no letter immediately
     * before and none immediately after, whatever alphabet the letter belongs to.
     * A term containing a space (a phrase) works unchanged — the boundary is only
     * asked about at its two ends.
     */
    private function pattern(string $term): string
    {
        return '/(?<!\p{L})'.preg_quote($term, '/').'(?!\p{L})/iu';
    }
}
