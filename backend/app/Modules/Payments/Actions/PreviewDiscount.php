<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Data\AppliedDiscount;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Support\DiscountResolver;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use DomainException;

/**
 * What a code would take off, before any money moves (spec 011 · FR-011).
 *
 * ⚠️ IT CONSUMES NO CEILING. The claim lives in {@see RedeemCoupon} alone; a
 * preview that took a place would let anybody exhaust a campaign from a form
 * they never submit, and the endpoint it sits behind is the one whose rate limit
 * exists *because* it is guessed at.
 *
 * ⚠️ THE SUBJECT IS RESOLVED HERE AND ITS PRICE IS READ FROM THE ROW. Taking a
 * line total from the request would let a buyer ask «what would this code do to
 * a purchase of one million» — free, unlimited, and it would answer. Worse, it
 * would need the workspace from the request too, which is exactly the oracle the
 * uniform refusal exists to close: pass another teacher's id and the answer tells
 * you whether their code exists.
 *
 * ⚠️ `credit_package` IS DELIBERATELY NOT PREVIEWABLE, AND THE COUPON STILL
 * WORKS ON THAT PATH. A package has no price column — its price is computed per
 * course from that teacher's approved settlement rate (006), so «the price of
 * this package» is not a question with one answer. `PurchaseCredits` applies the
 * code against the price it has just computed; what is missing here is the
 * preview, not the discount.
 */
class PreviewDiscount extends Action
{
    public function __construct(
        private readonly DiscountResolver $resolver,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    public function handle(User $buyer, CouponScope $kind, string $uuid, ?string $code): AppliedDiscount
    {
        [$workspaceId, $lineTotal] = match ($kind) {
            CouponScope::StoreItem => $this->storeItem($uuid),
            CouponScope::Course => $this->course($buyer, $uuid),
            CouponScope::CreditPackage => throw new DomainException(
                'يظهر خصم حزم الأرصدة على شاشة الشراء نفسها.',
            ),
        };

        return $this->resolver->resolve($buyer, $workspaceId, $lineTotal, $code, $kind, $uuid);
    }

    /**
     * @return array{int, int}
     *
     * ⚠️ RESOLVED WITHOUT ROUTE-MODEL BINDING AND WITHOUT THE GLOBAL SCOPE, for
     * the reason `PurchaseStoreItem` writes down: `WorkspaceScope` adds no
     * condition when the context is null, and a student's context is always
     * null — so an implicit binding here would resolve any teacher's product,
     * inactive ones included. `is_active` is the guard that the scope is not.
     */
    private function storeItem(string $uuid): array
    {
        $item = StoreItem::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->first();

        if ($item === null) {
            throw new DomainException('هذا المنتج غير متاح.');
        }

        // Quantity is deliberately absent: a percentage is unchanged by it and a
        // fixed amount clamped at one unit is the conservative answer. The exact
        // figure comes back from the purchase itself, computed on the real line.
        return [(int) $item->workspace_id, (int) $item->price_minor];
    }

    /**
     * @return array{int, int}
     */
    private function course(User $buyer, string $uuid): array
    {
        $course = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->first();

        if ($course === null) {
            throw new DomainException('هذا الكورس غير متاح.');
        }

        /*
        | ⚠️ A COURSE IS NOT PUBLIC THE WAY A STORE ITEM IS, so «does this exist»
        | is answered by the same predicate the marketplace uses rather than by
        | the bare row: a draft course's price read back through a coupon preview
        | is the drafts leak `PublicExposureTest` exists to catch, reached through
        | an endpoint it does not walk.
        */
        $visible = $course->status === 'published'
            || $this->enrollments->hasActiveEnrollmentInWorkspace($buyer, (int) $course->workspace_id);

        if (! $visible) {
            throw new DomainException('هذا الكورس غير متاح.');
        }

        return [(int) $course->workspace_id, (int) $course->price_minor];
    }
}
