<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Shared\Support\CountedNoun;

/**
 * «١٢ حصّة» / «شهر واحد» — what a plan actually sells, in one sentence (٠٣٦ · US2).
 *
 * ⛔ ONE SPELLING FOR EVERY BACKEND SCREEN. The panel's plan table, the pricing
 * form and the approval queue each printed `duration_days.' يوماً'` directly, so
 * the day a plan could be sold by SESSIONS all three printed **«٠ يوماً»** — at
 * the officer deciding a teacher's price, about a plan with no duration at all.
 * Deriving «which shape is this» in each of them is the two-spellings defect this
 * tree has paid for a dozen times over.
 *
 * ⚠️ AND THE COUNT GOES THROUGH `CountedNoun`, NEVER A TEMPLATE LITERAL. Arabic
 * agrees the noun with its number across five CLDR bands, so «٢ حصص» is wrong
 * where «حصّتان» is right — and 12 and 30 are precisely the band where a literal
 * happens to agree, which is why the tests for this use neither.
 *
 * ⚠️ IT TAKES TWO NULLABLE INTEGERS RATHER THAN A `Plan`. Its other caller is the
 * ORDER SNAPSHOT, which is not a plan row and must not become one: a plan edited
 * while a bank transfer sits in review must not change what the buyer was sold.
 *
 * Returns `null` for a row carrying neither, so a caller joining parts with « · »
 * drops it rather than printing a dash inside a sentence; a table cell under a
 * «المدّة» header supplies its own placeholder.
 */
final class PlanShape
{
    public static function describe(?int $durationDays, ?int $sessionCount): ?string
    {
        if ($sessionCount !== null && $sessionCount > 0) {
            return CountedNoun::of($sessionCount, [
                'one' => 'حصّة واحدة',
                'two' => 'حصّتان',
                'few' => 'حصص',
                'many' => 'حصّة',
                'other' => 'حصّة',
            ]);
        }

        /*
        | ⛔ `> 0`, NOT `!== null`. A zero duration is a half-written row, and
        | «٠ يوماً» is the sentence that was already on the officer's screen —
        | the same zero that, written to a subscription, produces a window whose
        | end equals its start.
        */
        if ($durationDays !== null && $durationDays > 0) {
            return $durationDays === 1 ? 'يوم واحد' : $durationDays.' يوماً';
        }

        return null;
    }
}
