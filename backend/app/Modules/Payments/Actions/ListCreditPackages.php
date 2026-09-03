<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Data\PackagePrice;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\CostPlusPricing;
use App\Modules\Payments\Support\CourseParticipation;
use App\Modules\Payments\Support\StopSellingGuard;
use App\Shared\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The packages a student may buy on one course, priced.
 *
 * ⚠️ MEMBERSHIP IS PROVEN BEFORE ANYTHING IS PRICED, and 403 is the answer for a
 * stranger — not an unpriced list, not an empty one.
 *
 * The price is `(rate + operating fee) × credits`, grossed up for the gateway,
 * and both of the platform's components are CONSTANTS. So anyone who can see
 * their own totals for two package sizes can solve for those constants, and
 * then invert any other course's total straight back to its teacher's approved
 * settlement rate — exactly. Several package sizes make the system
 * overdetermined, so rounding hides nothing. That is why the guard is
 * participation in this course rather than merely being signed in.
 *
 * @see CostPlusPricing
 */
class ListCreditPackages extends Action
{
    public function __construct(
        private readonly CostPlusPricing $pricing,
        private readonly StopSellingGuard $sales,
        private readonly CourseParticipation $participation,
    ) {}

    /**
     * @return list<array{package: CreditPackage, price: PackagePrice}>
     */
    public function handle(User $student, Course $course, ?User $grantedBy = null): array
    {
        /*
        | The SAME partial skip as `PurchaseCredits` — and it belongs here for a
        | reason that is easy to miss: this is what the officer's screen prices a
        | grant with BEFORE saving (024 · FR-006). Left guarded, every brand-new
        | student — the common case — shows an empty package list, and the officer
        | reads "nothing to sell" about a course that sells fine.
        |
        | The seller refusal still runs on both paths: two totals on two package
        | sizes solve for the platform's constants, and reading them is exactly
        | what this Action's own docblock is about.
        */
        if ($grantedBy === null) {
            if (! $this->participation->isPartyTo($student, $course)) {
                throw new AuthorizationException('لا يمكنك شراء أرصدة على كورس لست طرفاً فيه.');
            }
        } elseif ($this->participation->isSeller($student, $course)) {
            throw new AuthorizationException('لا يمكن منح أرصدة لمن يدرّس هذا الكورس.');
        }

        // A course whose teacher stopped delivering sells nothing (FR-021ط), and
        // one whose teacher has no approved rate cannot be priced at all
        // (FR-021ز). Both answer with an EMPTY LIST rather than an error: there
        // is nothing wrong with the request, there is simply nothing to sell.
        if (! $this->sales->maySell($course)) {
            return [];
        }

        $priced = [];

        foreach (CreditPackage::query()->where('is_active', true)->orderBy('sort_order')->orderBy('credits')->get() as $package) {
            $price = $this->pricing->price($package, (int) $course->getKey(), now());

            if ($price !== null) {
                $priced[] = ['package' => $package, 'price' => $price];
            }
        }

        return $priced;
    }
}
