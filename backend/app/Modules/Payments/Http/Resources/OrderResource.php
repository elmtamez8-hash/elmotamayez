<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Data\SubscriptionIntent;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at,
            'kind' => $this->kind,
            'course_title' => $this->course?->title,
            /*
            | Who paid — and it was ABSENT, which made the approval screen a
            | list of amounts with nobody's name on it. Two credit purchases on
            | one course were indistinguishable, and the officer pressing
            | «اعتماد» could not say whose money they were approving.
            |
            | Gated on ORDERS_VIEW_ALL rather than sent to everyone: the buyer
            | already knows who they are (`is_mine`), and this Resource is the
            | same one their own list renders.
            */
            'payer_name' => $this->when($this->viewerSeesAll($request), fn () => $this->user->name),
            'payer_email' => $this->when($this->viewerSeesAll($request), fn () => $this->user->email),
            /*
            | 024 · FR-008ب — who CREATED this order when its owner did not.
            | `null` means the buyer started it themselves, which is every
            | order written before 024. Behind the same gate as the payer:
            | «a member of staff called X made this for you» is not the
            | student's business, and it is a colleague's name on a
            | customer's screen.
            */
            'granted_by_name' => $this->when($this->viewerSeesAll($request), fn () => $this->grantor?->name),
            'has_receipt' => $this->hasMedia('receipt'),
            // Lets the buyer's client tell "upload your receipt" apart from a
            // staff member looking at someone else's order.
            'is_mine' => $this->user_id === $request->user()?->getKey(),
            // NOT getFirstMediaUrl(): the receipt collection uses the `local`
            // disk, which has no `url` in config/filesystems.php, so spatie fell
            // back to the conventional /storage/{id}/{file} path — a path that
            // serves the *public* disk. Every receipt link 403'd, and any that
            // had worked would have been a financial document on a public path.
            'receipt_url' => $this->hasMedia('receipt')
                ? URL::temporarySignedRoute(
                    'orders.receipt',
                    now()->addMinutes(15),
                    ['order' => $this->uuid],
                )
                : null,
            // Only while the answer is still owed. On a decided order the promise
            // is spent, and repeating it beside "معتمد" reads as a second wait
            // about to begin.
            'review_sla_hours' => $this->isPending()
                ? app(BillingSettings::class)->reviewSlaHours()
                : null,
            /*
            | 027 · FR-014 · FR-017 — what was bought, as it was at the moment of
            | buying. Null for every other order kind and for a subscription
            | order written before this spec.
            |
            | ⚠️ THROUGH `SubscriptionIntent`, NEVER BY REACHING INTO
            | `$this->metadata` HERE. One reader for one snapshot: the student's
            | own «الطلبات» list and the officer's queue render the same four
            | facts, and a second spelling returns null silently at the first
            | renamed key.
            |
            | ⚠️ AND A MISSING KEY IS «—», NOT A LOOKUP. A Resource runs once per
            | row, so a fallback query for a legacy order would be an N+1 on the
            | one screen that lists every pending order on the platform.
            */
            'subscription' => SubscriptionIntent::fromOrder($this->resource)?->toArray(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * ⚠️ The same cut as `OrderController::index()` and `OrderPolicy::view()`.
     * A third spelling of one question is how one answer reaches the screen and
     * another reaches the door — so this reads the permission, never a role.
     */
    private function viewerSeesAll(Request $request): bool
    {
        return $request->user()?->can(Permissions::ORDERS_VIEW_ALL) ?? false;
    }
}
