<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

/**
 * Why one session is open or shut for one student — computed once, read twice.
 *
 * ⚠️ ONE VERDICT, TWO FORMATTINGS. The eligibility screen shows what is missing
 * and which rule decided (FR-038 · T174); `BookingEligibility` needs one
 * sentence. Computing them separately is how a student reads one reason on the
 * page and is refused for another at the button — the disagreement nobody
 * notices until somebody quotes the screen back at their teacher.
 */
class UnlockVerdict
{
    /**
     * @param  'default'|'course'|'none'  $ruleScope  which rule decided, so the payload can
     *                                                say so — precedence is invisible otherwise, and a
     *                                                teacher debugging a block cannot see which of their
     *                                                two rules is speaking
     * @param  list<string>  $missing  machine-readable: attendance · assignment · score
     */
    public function __construct(
        public readonly bool $open,
        public readonly string $ruleScope = 'none',
        public readonly array $missing = [],
        public readonly ?string $reason = null,
        public readonly bool $exempt = false,
    ) {}

    /** @param  'default'|'course'|'none'  $ruleScope */
    public static function open(string $ruleScope = 'none', bool $exempt = false): self
    {
        return new self(true, $ruleScope, [], null, $exempt);
    }

    /**
     * @param  'default'|'course'  $ruleScope
     * @param  list<string>  $missing
     */
    public static function shut(string $ruleScope, array $missing, string $reason): self
    {
        return new self(false, $ruleScope, $missing, $reason);
    }
}
