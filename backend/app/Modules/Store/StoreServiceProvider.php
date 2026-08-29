<?php

declare(strict_types=1);

namespace App\Modules\Store;

use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;

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

    /*
    | ⚠️ THE COMPLIANCE TAG LANDS WITH THE FIRST MIGRATION, NOT HERE (T032a).
    |
    |     $this->app->tag([StorePersonalData::class], 'compliance.personal_data');
    |
    | It is one line and it is not optional — `shipments` carries a child's home
    | address and phone number, and `PersonalDataContractCoverageTest` turns the
    | build red the moment this module owns a migration holding a personal column.
    | But `StorePersonalData` walks `StoreOrder` and `Shipment`, so writing it
    | before those models exist would mean writing a `PersonalDataOwner` whose
    | three methods return nothing — the shape of guard this repository has
    | already shipped and recorded twice, where the tag is present, the test is
    | green, and an erasure request completes while leaving the address exactly
    | where it was.
    |
    | So the module ships its scaffold with NO personal column and NO tag, which
    | is consistent rather than half-guarded, and the two arrive together.
    */
}
