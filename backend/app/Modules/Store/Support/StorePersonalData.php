<?php

declare(strict_types=1);

namespace App\Modules\Store\Support;

use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreOrder;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use App\Shared\Support\GuardianPermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Store's half of the data-rights contract (spec 013 · spec 011 · US1).
 *
 * ⚠️ THIS FILE IS NOT OPTIONAL AND IT IS NOT DOCUMENTATION. `shipments` carries a
 * child's home address and phone number, and `PersonalDataContractCoverageTest`
 * derives the module list from migration directories — so the build goes red the
 * moment this module owns a migration declaring a personal column. It ships in
 * the same change as those three tables, deliberately: written earlier it would
 * have been three methods returning nothing, which is the shape of guard this
 * repository has already recorded twice — the tag present, the test green, and
 * an erasure request completing while leaving the address exactly where it was.
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in `StoreServiceProvider`. `Compliance`
 * never names a table here.
 *
 * @see PersonalDataOwner
 */
class StorePersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'store';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['store_purchase', 'shipping_address'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY — the contract's second obligation.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        if (! $subject->mayReceive(GuardianPermission::Payments)) {
            yield from ExportWalk::none(...$this->describe());

            return;
        }

        $userId = $subject->user->getKey();

        /*
        | ⚠️ THE FIELD LIST IS COMPOSED HERE, NOT `->toArray()`-ED — the contract's
        | first obligation. And what it deliberately omits is `commission_minor`
        | and `teacher_net_minor`: the platform's cut and the teacher's share are
        | not facts about the buyer, and a price solvable for the teacher's rate
        | is the exact leak `StudentBalanceAllowlist` exists to prevent. What the
        | buyer is owed is what they paid.
        */
        yield from ExportWalk::keyed(
            'store_purchase',
            StoreOrder::query()
                ->withoutWorkspaceScope()
                ->leftJoin('store_items', 'store_items.id', '=', 'store_orders.store_item_id')
                ->where('store_orders.buyer_user_id', $userId)
                ->select(['store_orders.*', 'store_items.title as item_title']),
            fn (StoreOrder $order): array => [
                'uuid' => $order->uuid,
                'item_title' => $order->getAttribute('item_title'),
                'quantity' => $order->quantity,
                'unit_price_minor' => $order->unit_price_minor,
                'discount_minor' => $order->discount_minor,
                'currency' => $order->currency,
                'purchased_at' => ExportWalk::at($order->created_at),
                'fulfilled_at' => ExportWalk::at($order->fulfilled_at),
                'first_accessed_at' => ExportWalk::at($order->first_accessed_at),
                'refunded_at' => ExportWalk::at($order->refunded_at),
            ],
            column: 'store_orders.id',
        );

        /*
        | The address is reached through the purchase, because `shipments` names
        | no user: it holds a RECIPIENT, who may be the buyer's parent or an aunt
        | the parcel was sent to. Joining through `store_orders.buyer_user_id` is
        | what makes "this person's addresses" answerable at all.
        */
        yield from ExportWalk::keyed(
            'shipping_address',
            Shipment::query()
                ->withoutWorkspaceScope()
                ->join('store_orders', 'store_orders.id', '=', 'shipments.store_order_id')
                ->where('store_orders.buyer_user_id', $userId)
                ->select(['shipments.*']),
            fn (Shipment $shipment): array => [
                'uuid' => $shipment->uuid,
                'recipient_name' => $shipment->recipient_name,
                'phone' => $shipment->phone,
                'address_line' => $shipment->address_line,
                'notes' => $shipment->notes,
                'status' => $shipment->status->value,
                'tracking_ref' => $shipment->tracking_ref,
                'status_changed_at' => ExportWalk::at($shipment->status_changed_at),
            ],
            column: 'shipments.id',
        );
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk never uses `chunk`.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Anonymise) {
            return 0;
        }

        /*
        | ⚠️ THE PURCHASE ROW SURVIVES UNTOUCHED, AND `store_purchase` IS A
        | `Retain` CATEGORY FOR THE REASON `payment_record` IS: a sale is a legal
        | obligation and its row is what proves what was bought and by whom. The
        | identity is severed at the `users` row instead, which is what makes
        | `SC-007` assertable at all.
        |
        | What genuinely goes is the ADDRESS — a place where a child lives, which
        | no invariant counts and no accountant reads. The shipment row stays, so
        | the purchase still shows that something was posted.
        */
        $recipient = $this->addressQuery()
            ->where('store_orders.buyer_user_id', $subject->user->getKey());

        return $this->clearAddresses($recipient, $limit);
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person.
     *
     * @param  list<int>  $exemptUserIds  subjects under a live hold — their rows stay.
     */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        if ($category !== 'shipping_address' || $mode !== ExpiryBehaviour::Anonymise) {
            return 0;
        }

        $query = $this->addressQuery()
            ->where('shipments.created_at', '<', $before->toDateTimeString());

        /*
        | ⚠️ THE HOLD EXEMPTION GOES THROUGH `store_orders`, because `shipments`
        | names nobody. FR-030's fourth door: retention needs no request, so a
        | hold that only suspended erasure REQUESTS would let this job delete the
        | rows a court ordered kept — on a schedule, with the hold sitting green
        | beside it and nothing logged.
        */
        if ($exemptUserIds !== []) {
            $query->whereNotIn('store_orders.buyer_user_id', $exemptUserIds);
        }

        return $this->clearAddresses($query, $limit);
    }

    /**
     * Shipments reachable from a purchase, which is the only route to a person.
     *
     * @return Builder<Shipment>
     */
    private function addressQuery(): Builder
    {
        return Shipment::query()
            ->withoutWorkspaceScope()
            ->join('store_orders', 'store_orders.id', '=', 'shipments.store_order_id');
    }

    /**
     * @param  Builder<Shipment>  $query
     */
    private function clearAddresses(Builder $query, int $limit): int
    {
        /*
        | ⚠️ `!= ''` IS THE CONVERGENCE GUARD, NOT AN OPTIMISATION. Without it
        | every already-cleared row matches again tomorrow and is counted again in
        | `retention_sweep_runs` — so `SC-010`'s "two runs, same state" holds for
        | the data while the log reports two different numbers for ever.
        |
        | Empty strings rather than nulls: the three columns are NOT NULL, and
        | `->change()` on any of them REBUILDS the table on SQLite. Precedent, in
        | the same words: `complaints.reason`.
        */
        return $query
            ->where('shipments.recipient_name', '!=', '')
            ->limit($limit)
            ->update([
                'shipments.recipient_name' => '',
                'shipments.phone' => '',
                'shipments.address_line' => '',
                'shipments.notes' => null,
            ]);
    }
}
