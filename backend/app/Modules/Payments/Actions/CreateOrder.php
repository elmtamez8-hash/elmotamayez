<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\DiscountResolver;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The order a student pays to enrol on a course.
 *
 * ⚠️ IT GAINED A DISCOUNT HOOK IN SPEC 011 (T067 · FR-012). A coupon's scope
 * covers `course` and `credit_package` as well as `store_item`, and for a phase
 * this was the only one of the three purchase paths that could not take a code —
 * so the platform could create a coupon for a course and no screen in the
 * product could spend it.
 *
 * ⚠️ THE DISCOUNT MOVES `amount_minor` AND NOTHING ELSE. A course fee reaches
 * the teacher through Settlement, off a `SessionDelivered` event that knows
 * nothing about this row, so «the discount does not touch the teacher's due»
 * (FR-010) holds here by construction — there is no split column to get wrong.
 */
class CreateOrder extends Action
{
    public function __construct(
        private readonly DiscountResolver $discounts,
        private readonly RedeemCoupon $redeem,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(Course $course, User $user, ?string $couponCode = null): Order
    {
        /*
        | ⚠️ **034 . FR-023 — AND THE CONDITION IS TWO CONDITIONS, NOT ONE.**
        | A course taught in groups with no assignable group left is a seat that
        | does not exist, and selling it is a refund with a student's hope
        | attached. But `assignableCohortsExist()` answers `false` for a course
        | with NO GROUPS AT ALL by the identical value — so a guard written with
        | that one line refuses the sale of every RECORDED course on the
        | platform, which is FR-025 inverted onto the money path. Both halves
        | live behind {@see CohortDirectory::courseIsFull()}, which is also what
        | the free door, the waitlist and the public card read.
        |
        | ⚠️ AND IT IS ASKED ONCE MORE AT APPROVAL, ATOMICALLY. This is a read
        | and reads go stale; FR-024أ claims the seat inside `ApproveOrder`
        | BEFORE any money is taken. This refusal is the one the student sees
        | instead of paying for a queue.
        */
        if ($this->cohorts->courseIsFull((int) $course->getKey())) {
            throw new DomainException('اكتملت مجموعات هذا الكورس. سجِّل في الدَّور ونُعلِمك حين يُفتح مكان.');
        }

        // Against the COURSE's workspace, never `WorkspaceContext::id()` — null
        // for every student, which would silently let only platform coupons match.
        $discount = $this->discounts->resolve(
            $user,
            (int) $course->workspace_id,
            (int) $course->price_minor,
            $couponCode,
            CouponScope::Course,
            (string) $course->uuid,
        );

        return DB::transaction(function () use ($course, $user, $discount): Order {
            $order = Order::create([
                'workspace_id' => $course->workspace_id,
                'user_id' => $user->getKey(),
                'course_id' => $course->getKey(),
                'amount_minor' => (int) $course->price_minor - $discount->amountMinor,
                'currency' => $course->currency,
                'provider' => 'manual',
                'status' => 'pending',
            ]);

            // Inside the transaction: the claim on the coupon's ceiling throws
            // when the last place went in between, and the rollback is what stops
            // an order existing at a price the coupon no longer justifies.
            $this->redeem->handle($order, $discount);

            return $order;
        });
    }
}
