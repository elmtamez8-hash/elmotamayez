<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Enums\TermPolicy;

/**
 * The list a workspace starts with.
 *
 * ⚠️ DELIBERATELY SHORT, AND NOT A DICTIONARY OF INSULTS. Over-blocking is what
 * teaches a room to spell around the filter, after which the list stops working
 * for the words it was actually written for. What is here is the shape of the
 * three policies rather than an attempt at coverage — the teacher edits their own
 * list, which is the whole reason it is rows and not a constant.
 *
 * ⚠️ AND THE CONTACT PATTERNS ARE `mask`, NOT `block`. A student writing their
 * phone number in a class chat is usually being helpful; refusing the message
 * loses what they said, while masking keeps the sentence and removes the number.
 * Taking the conversation off the platform is the risk, and it is a review
 * matter, not a refusal.
 *
 * One place, read by the listener AND by the backfill migration — two lists would
 * disagree at the first edit, and the disagreement would be invisible.
 */
final class DefaultBlockedTerms
{
    /** @return array<string, TermPolicy> */
    public static function rows(): array
    {
        return [
            // Blocked outright: the words a teacher would remove by hand anyway.
            'غبي' => TermPolicy::Block,
            'حمار' => TermPolicy::Block,
            'كلب' => TermPolicy::Block,

            // Delivered, and a human is told: taking the lesson off the platform
            // is a judgement call, not a rule.
            'واتساب' => TermPolicy::Review,
            'تلغرام' => TermPolicy::Review,
            'خارج المنصة' => TermPolicy::Review,
        ];
    }
}
