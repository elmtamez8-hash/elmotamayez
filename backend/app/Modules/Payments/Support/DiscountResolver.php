<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Models\User;
use App\Modules\Payments\Data\AppliedDiscount;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Models\Coupon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * What comes off one line, and why (spec 011 · FR-012 · FR-014 · D13 · D17).
 *
 * ⚠️ IT RETURNS ONE DISCOUNT. «The highest alone applies, no stacking» is the
 * declared policy, and it is decided here — in the one place both candidates are
 * in scope — rather than by whichever caller adds them up last.
 *
 * ⚠️ THE FIXED CLAMP IS INSIDE {@see CouponValueKind}
 * AND NOWHERE ELSE. Clamping in each path that applies a coupon is a rule with
 * as many spellings as there are paths, and there are three; the one that gets
 * forgotten is the one where a fixed coupon of 50 on a 30-riyal notebook takes
 * the total below zero.
 *
 * ⚠️ THE REFUSAL DOES NOT DISTINGUISH «UNKNOWN» FROM «NOT YOURS». `code` is
 * unique platform-wide while `workspace_id` narrows who may spend it, so a
 * detailed answer tells a guesser that a code exists AT ANOTHER TEACHER's — the
 * payment webhook's uniform-202 rule reached from a second direction. «Expired»
 * and «used up» ARE stated, because by then the caller has proved they hold a
 * real code in their own scope and the sentence tells them something they can
 * act on.
 */
class DiscountResolver
{
    public function __construct(private readonly SiblingDiscount $siblings) {}

    /**
     * @param  int  $workspaceId  the workspace of the THING BEING BOUGHT.
     *
     * ⚠️ NEVER `WorkspaceContext::id()`. It is null for every student — a
     * student is a member of no workspace — so reading the context here would
     * compare `workspace_id` against null and let only platform coupons ever
     * match, on every purchase, in silence. It comes from the item, the course
     * or the package.
     */
    public function resolve(
        User $buyer,
        int $workspaceId,
        int $lineTotalMinor,
        ?string $code,
        CouponScope $subjectKind,
        string $subjectUuid,
    ): AppliedDiscount {
        $sibling = $this->siblingCandidate($buyer, $lineTotalMinor);

        if ($code === null || trim($code) === '') {
            return $sibling;
        }

        $coupon = $this->couponFor($code, $workspaceId, $subjectKind, $subjectUuid);
        $couponDiscount = $coupon->value_kind->discountOn($coupon->value, $lineTotalMinor);

        // The highest alone. A tie goes to the coupon: the buyer typed it, and
        // being told a code «did nothing» when it matched the family rate exactly
        // reads as the code being rejected.
        return $couponDiscount >= $sibling->amountMinor
            ? AppliedDiscount::coupon($coupon, $couponDiscount)
            : $sibling;
    }

    private function siblingCandidate(User $buyer, int $lineTotalMinor): AppliedDiscount
    {
        $percent = $this->siblings->percentFor($buyer);

        if ($percent === 0) {
            return AppliedDiscount::none();
        }

        return AppliedDiscount::sibling(
            $this->siblings->discountFor($buyer, $lineTotalMinor),
            $percent,
        );
    }

    /**
     * The one coupon this code may be, or a refusal.
     *
     * ⚠️ THE PARENTHESES AROUND THE SCOPE CLAUSE ARE NOT DECORATION. Written
     * flat, the `OR` splits the whole predicate and every later condition binds
     * to one side of it — so an EXPIRED coupon is accepted, and a coupon
     * belonging to another teacher along with it. Exactly the `orWhereNull`
     * defect that discarded 013's age bound and would have deleted a whole
     * table.
     */
    private function couponFor(string $code, int $workspaceId, CouponScope $subjectKind, string $subjectUuid): Coupon
    {
        $coupon = Coupon::query()
            // Normalised in PHP. `UPPER(code)` in SQL throws away the index on
            // the one path whose rate limit exists *because* it is guessed at —
            // and MySQL would match case-insensitively where SQLite does not, so
            // leaving it to the engine proves the opposite of what a local test
            // claims.
            ->where('code', Coupon::normaliseCode($code))
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('workspace_id')
                ->orWhere('workspace_id', $workspaceId))
            ->first();

        if ($coupon === null || ! $this->coversSubject($coupon, $subjectKind, $subjectUuid)) {
            // ONE sentence for «no such code» and for «not for this». See the
            // class docblock: a distinct answer is an oracle.
            throw new DomainException('هذا الكود غير صالح لهذه العملية.');
        }

        if (! $coupon->isWithinWindow(now())) {
            throw new DomainException('انتهت صلاحية هذا الكود.');
        }

        if ($coupon->isExhausted()) {
            // Read here so the buyer is not invited to pay against a coupon
            // visibly gone. It is a CHECK, not a claim — the cap is taken by a
            // conditional UPDATE in `RedeemCoupon`, and this read cannot and does
            // not pretend to hold it.
            throw new DomainException('استُنفد هذا الكود.');
        }

        return $coupon;
    }

    private function coversSubject(Coupon $coupon, CouponScope $subjectKind, string $subjectUuid): bool
    {
        if ($coupon->scope_type === null) {
            // The ordinary seasonal-campaign shape: anything on the platform.
            return true;
        }

        return $coupon->scope_type === $subjectKind && $coupon->scope_uuid === $subjectUuid;
    }
}
