<?php

declare(strict_types=1);

namespace App\Modules\Store;

use App\Modules\Payments\Events\PaymentApproved;
use App\Modules\Payments\Events\PaymentCaptured;
use App\Modules\Store\Listeners\FulfilOnPaymentApproved;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Policies\ShipmentPolicy;
use App\Modules\Store\Policies\StoreItemPolicy;
use App\Modules\Store\Support\StorePersonalData;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 011 — the teacher's store: books and notes, digital or printed.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never register it by hand in
 * bootstrap/providers.php.
 *
 * ⚠️ A MODULE OF ITS OWN RATHER THAN A CORNER OF `Payments`, and the reason is
 * the shape of what it holds rather than tidiness. Selling a physical object
 * brings stock, a postal address and a fulfilment state machine — none of which
 * the credit engine has any use for, and all of which would sit inside the
 * context `ContextIsolationTest` calls "the student billing context". The money
 * still flows through Payments: a purchase creates an `Order(kind: store)` and
 * waits for `PaymentApproved` like everything else. What lives here is what
 * happens AFTER the money arrives.
 *
 * ⚠️ AND A NEW MODULE COSTS SIX THINGS, NOT THREE. The three that are famous —
 * a row in `phpstan.neon`, a capital `M` on `Database/Migrations` (a mismatch
 * resolves fine on Windows and loads ZERO migrations on Linux), and the module's
 * name in `ContextIsolationTest`'s table lists — plus three the spec 011 agent
 * review found missing from that list:
 *
 *  1. Its own `it()` block in `ContextIsolationTest`: the four import sweeps name
 *     their modules LITERALLY, and that file says of itself that "a module
 *     written in 2027 is invisible to both".
 *  2. A registered `PersonalDataOwner` — `shipments` carries a child's home
 *     address and phone number, and `PersonalDataContractCoverageTest` derives
 *     modules from migration directories and exempts three, of which this is not
 *     one. The build is red until it exists.
 *  3. `docs/README.md` and `docs/erd.md`, which the constitution's workflow
 *     section makes gates rather than courtesies.
 */
class StoreServiceProvider extends Module
{
    protected string $name = 'Store';

    /**
     * ⚠️ ONE TAGGED LINE, AND `Compliance` NEVER NAMES A TABLE HERE. That is the
     * whole reason a requirement crossing fourteen schemas does not violate
     * Constitution III.
     *
     * It landed with the migrations rather than with the scaffold, deliberately:
     * `StorePersonalData` walks `StoreOrder` and `Shipment`, so written earlier
     * it would have been three methods returning nothing — the tag present, the
     * test green, and an erasure request completing while leaving a child's home
     * address exactly where it was.
     */
    public function register(): void
    {
        parent::register();

        $this->app->tag([StorePersonalData::class], 'compliance.personal_data');
    }

    /**
     * ⚠️ BOTH EVENTS, NOT JUST `PaymentApproved`. A manual transfer approved from
     * the panel and a gateway capture are the same fact reached two ways, and
     * Payments has already paid for binding only one of them — `CreateEnrollmentFromOrder`
     * and `CreditPurchaseOnApproval` are each listed twice in that module's
     * provider for exactly this reason. Bind one and every card payment on the
     * platform buys a book that is never delivered, with no error anywhere.
     */
    public function boot(): void
    {
        parent::boot();

        Event::listen(PaymentApproved::class, FulfilOnPaymentApproved::class);
        Event::listen(PaymentCaptured::class, FulfilOnPaymentApproved::class);

        /*
        | ⚠️ BOUND EXPLICITLY, BECAUSE THE GUESSER FAILS **OPEN**. Laravel resolves
        | a missing policy into "no policy applies" rather than into a refusal, so
        | a forgotten line here is not a 403 anybody notices — it is every deny in
        | both files above becoming an allow, silently. `taxonomy.manage` shipped
        | that way for a whole phase.
        */
        Gate::policy(StoreItem::class, StoreItemPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
    }
}
