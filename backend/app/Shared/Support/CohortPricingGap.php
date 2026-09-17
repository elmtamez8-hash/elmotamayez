<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Contracts\CohortPricingReasonDirectory;
use App\Shared\Contracts\SellableCohortDirectory;

/**
 * Why a group is not listed for sale — the TEACHER's answer (٠٣٦ · FR-014).
 *
 * ⛔ THREE CASES, AND THE MIDDLE ONE IS THE WHOLE REASON THIS TYPE EXISTS. A
 * teacher reading a number that dropped with nothing beside it reads it as a
 * mistake of theirs and goes hunting for a setting that was never the problem —
 * and for {@see self::AwaitingPricing} there is no setting, because the platform
 * has not priced the plan yet. The other two name an act the teacher can perform
 * this minute.
 *
 * ⚠️ AND IT NEVER TRAVELS TO A STUDENT OR A GUEST. Whether a plan has been
 * priced is a commercial fact between one teacher and the platform;
 * {@see SellableCohortDirectory} deliberately answers
 * without a reason for exactly that, and the shared cohort payload is the
 * measured trap — one transformer feeds the student's picker and both of the
 * teacher's screens, so a field added there reaches the student too. The reason
 * is assembled on the teacher's path and nowhere else.
 *
 * @see CohortPricingReasonDirectory::pricingGapsFor()
 */
enum CohortPricingGap: string
{
    /** Nothing covers this group at all. The teacher writes a plan. */
    case NoPlan = 'no_plan';

    /**
     * A plan exists and the platform has not put a price on it yet.
     *
     * ⚠️ THE ONE CASE WITH NOTHING FOR THE TEACHER TO DO, which is why it may
     * not be merged into {@see self::NoPlan}: the two sentences differ by whose
     * turn it is.
     */
    case AwaitingPricing = 'awaiting_pricing';

    /** A priced plan exists and is switched off. The teacher switches it on. */
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::NoPlan => 'لا توجد باقة تغطّي هذه المجموعة',
            self::AwaitingPricing => 'باقتها بانتظار التسعير من المنصّة',
            self::Disabled => 'باقتها معطَّلة',
        };
    }

    /** What the teacher does about it — empty when the answer is «wait». */
    public function remedy(): ?string
    {
        return match ($this) {
            self::NoPlan => 'أضِفْ باقة تغطّي هذه المجموعة أو كورسها.',
            self::AwaitingPricing => null,
            self::Disabled => 'فعِّلْ الباقة من صفحة الباقات.',
        };
    }
}
