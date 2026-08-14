<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Models\Order;
use App\Modules\Settlement\Actions\RecordDeduction;
use App\Modules\Settlement\Support\EloquentApprovedRateDirectory;
use App\Modules\Settlement\Support\TeacherFieldAllowlist;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\ApprovedRateDirectory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;

/*
| SC-009 · FR-030…FR-034 — the separation, as a build gate.
|
| The whole point of this context is that what a student pays and what a teacher
| is owed are two different questions with two different answers. That is an
| architectural claim, and an architectural claim with no gate decays one
| convenient join at a time — each one defensible on the afternoon it is written.
|
| These are text scans rather than AST walks because what is forbidden IS
| textual: naming the other context. Whoever writes `use App\Modules\Payments`
| under `Modules/Settlement/` finds out here rather than in review, or a year
| later when a refund quietly reduces a teacher's pay.
|
| Nothing here boots a workspace or touches a row: these cases read the source
| tree. They were the phase that could have been written first.
*/

/**
 * The tables each context creates, read from its own migrations.
 *
 * Derived rather than listed, deliberately. A hardcoded pair of lists goes stale
 * the day spec 006 adds its credit tables — and it goes stale silently, which is
 * the worst way for a guard to fail. Reading `Schema::create` means the two sides
 * are always exactly what each side actually built.
 *
 * @return list<string>
 */
function tablesCreatedBy(string $module): array
{
    $tables = [];

    foreach (glob(app_path("Modules/{$module}/Database/Migrations/*.php")) ?: [] as $file) {
        preg_match_all("/Schema::create\('([a-z_]+)'/", (string) file_get_contents($file), $matches);
        $tables = array_merge($tables, $matches[1]);
    }

    return array_values(array_unique($tables));
}

/** Every PHP file under one module. */
function moduleFiles(string $module): Finder
{
    return Finder::create()->files()->in(app_path("Modules/{$module}"))->name('*.php');
}

/**
 * One file's source with every comment removed.
 *
 * The payload scan below reads CODE. Without this it fires on the docblock that
 * explains why a field is absent — so the only way to keep it green would be to
 * stop writing down the reason, which is the opposite of what the guard is for.
 * CreditBalanceResource's own comment, naming this context to say that none of
 * its numbers appear, is exactly the case.
 *
 * Applied here and NOT to the two module scans above: those forbid an import and
 * a quoted table name, which is coupling wherever it appears, and a stricter
 * guard on the thing that actually joins the contexts is worth the false
 * positive it has never yet produced.
 */
function codeWithoutComments(string $source): string
{
    $kept = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept[] = is_array($token) ? $token[1] : $token;
    }

    return implode('', $kept);
}

// ---------------------------------------------------------------------------
// FR-030 — zero foreign keys, in both directions.
// ---------------------------------------------------------------------------

it('declares no foreign key between the settlement and billing schemas', function (): void {
    $settlementTables = tablesCreatedBy('Settlement');
    $billingTables = tablesCreatedBy('Payments');

    // Sanity: a scan that found no tables would pass by finding nothing, which
    // is the failure mode every architectural test has.
    expect($settlementTables)->toContain('teaching_units', 'ledger_entries', 'settlement_periods')
        // The credit tables are named here on purpose. The derivation above is
        // what keeps this list current, but a broken glob or a renamed directory
        // would make it return nothing and every scan below would pass by
        // finding nothing to forbid.
        // 007's two tables are named beside 006's for the same reason: the
        // derivation picks them up on its own, and naming them is what proves
        // the derivation still runs. `provider_callbacks` holds the provider's
        // raw body and `payment_reconciliation_runs` the sweep's findings —
        // both of them money, both of them the student's side of it.
        ->and($billingTables)->toContain(
            'orders',
            'payment_transactions',
            'credit_balances',
            'credit_transactions',
            'provider_callbacks',
            'payment_reconciliation_runs',
        );

    $offenders = [];

    foreach ([['Settlement', $billingTables], ['Payments', $settlementTables]] as [$module, $forbiddenTables]) {
        foreach (glob(app_path("Modules/{$module}/Database/Migrations/*.php")) ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbiddenTables as $table) {
                if (str_contains($contents, "'{$table}'")) {
                    $offenders[] = basename($file).' → '.$table;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------
// FR-031 · FR-032 — no query joins the two, and no billing event is consumed.
// ---------------------------------------------------------------------------

it('never names a billing model, table or event anywhere in the settlement module', function (): void {
    $forbidden = array_merge(
        // The import is what real coupling needs. A bare `Order` would match
        // `orderBy` and prose; the namespace matches only the thing itself.
        ['use App\Modules\Payments'],
        // Quoted table names — the form a raw join or an `exists` rule takes.
        array_map(fn (string $table): string => "'{$table}'", tablesCreatedBy('Payments')),
        // FR-032's second half: this context consumes NO event from the billing
        // side. The bridge runs one way only — SessionDelivered comes in from
        // 005, and nothing comes in from the student's money at all.
        array_map(
            fn (SplFileInfo $file): string => $file->getBasename('.php'),
            iterator_to_array(Finder::create()->files()->in(app_path('Modules/Payments/Events'))->name('*.php'))
        ),
    );

    $offenders = [];

    foreach (moduleFiles('Settlement') as $file) {
        $contents = $file->getContents();

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * ⚠️ The scan above is ONE-DIRECTIONAL, and spec 006 is what makes that matter.
 *
 * `moduleFiles('Settlement')` is the whole sweep: nothing has ever read the
 * billing side looking for a settlement reference, because until now the billing
 * side had no reason to want one. 006 gives it one — a credit package is priced
 * from the teacher's approved rate — and the sanctioned route is the shared
 * contract, which names neither module.
 *
 * So the reverse case: Payments MAY name `Shared\Contracts\ApprovedRateDirectory`
 * and MUST NOT name `App\Modules\Settlement`. Without it, the first developer to
 * find `RateResolver` and use it directly gets a green build and a join between
 * the two contexts.
 */
it('never names the settlement module anywhere in the billing module', function (): void {
    $forbidden = array_merge(
        ['use App\Modules\Settlement'],
        array_map(fn (string $table): string => "'{$table}'", tablesCreatedBy('Settlement')),
    );

    $offenders = [];

    foreach (moduleFiles('Payments') as $file) {
        $contents = $file->getContents();

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// And the permitted route, named — the mirror of the SessionDelivered case
// below. Asserting only the absence of the wrong coupling says nothing about
// whether the right one still exists: delete the binding and the scan above
// stays green over a module that can no longer price anything.
it('reaches the approved rate through the shared contract alone', function (): void {
    expect(interface_exists(ApprovedRateDirectory::class))->toBeTrue()
        // Bound by Settlement, resolved by Payments, and neither one names the
        // other to do it.
        ->and(app(ApprovedRateDirectory::class))
        ->toBeInstanceOf(EloquentApprovedRateDirectory::class);
});

// The bridge itself, named. Asserting the absence of every wrong integration
// says nothing about whether the right one is still there — delete the listener
// and the scan above stays green over a module that accrues nothing.
it('bridges to the rest of the product through SessionDelivered alone', function (): void {
    $listeners = Finder::create()->files()->in(app_path('Modules/Settlement/Listeners'))->name('*.php');

    $subscribed = [];

    foreach ($listeners as $file) {
        // Foreign modules only — this context's own events are its internal
        // wiring (a unit accrues, then it is written to the ledger), not a
        // bridge to anywhere.
        preg_match_all('/use App\\\\Modules\\\\(?!Settlement)\w+\\\\Events\\\\(\w+);/', $file->getContents(), $matches);
        $subscribed = array_merge($subscribed, $matches[1]);
    }

    expect(array_values(array_unique($subscribed)))->toBe(['SessionDelivered']);
});

// ---------------------------------------------------------------------------
// FR-033 · SC-008 — nothing about a teacher's price reaches a student.
// ---------------------------------------------------------------------------

it('keeps settlement vocabulary out of every payload outside this module', function (): void {
    // Field names, not concepts: a teacher's rate on a student's screen is what
    // this catches, and these words are how it would arrive.
    $forbidden = ['net_minor', 'gross_minor', 'settlement', 'teaching_unit', 'ledger_entr'];

    /*
    | ⚠️ `amount_minor` IS NO LONGER FORBIDDEN EVERYWHERE, and the reason is a
    | fact about the product rather than a concession to a failing test.
    |
    | It was on the list above because it was this context's private unit of
    | money and «appeared nowhere else». Spec 007 converted every money column
    | on the platform to minor units, so the word now names a student's own
    | order total as well — a number they must see in order to pay it.
    |
    | The narrowest correct guard is therefore an EXCEPTION FOR PAYMENTS, not a
    | deletion: anywhere else, a resource emitting `amount_minor` is still
    | reaching across the boundary, and Courses or LiveSessions naming it is
    | still the failure this test was written for.
    */
    $paymentsExempt = ['amount_minor'];

    $resources = Finder::create()
        ->files()
        ->in(app_path('Modules'))
        ->path('/Resources/')
        ->notPath('Settlement')
        ->name('*.php');

    $offenders = [];

    foreach ($resources as $file) {
        $contents = codeWithoutComments($file->getContents());
        $inPayments = str_contains(str_replace('\\', '/', $file->getRelativePathname()), 'Payments/');

        foreach (array_merge($forbidden, $inPayments ? [] : $paymentsExempt) as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// The stripper is what stands between "the absence is documented" and "the guard
// fires on its own documentation". One that returned an empty string would make
// the scan above pass over everything, so both halves are asserted.
it('strips comments without stripping code', function (): void {
    $source = (string) file_get_contents(
        app_path('Modules/Payments/Http/Resources/CreditBalanceResource.php'),
    );

    expect($source)->toContain('settlement')
        ->and(codeWithoutComments($source))
        ->not->toContain('settlement')
        ->toContain('class CreditBalanceResource');
});

// The scan above proves nothing is written down; this proves nothing arrives.
// A payload assembled outside a Resource — an array built in a controller, a
// column appended by a scope — would pass the text scan and still ship.
it('serves a student their own timetable with no trace of what the teacher earns', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    ClassSession::factory()->create([
        'workspace_id' => $workspace->getKey(),
    ]);

    Sanctum::actingAs($student);

    $payload = $this->getJson('/api/v1/class-sessions')->assertOk()->json();

    $keys = settlementPayloadKeys($payload);

    // An empty list would pass every assertion below by having no keys at all —
    // the failure mode of every "payload contains no X" test ever written.
    expect($payload['data'])->toHaveCount(1)
        ->and($keys)->toContain('seats');

    foreach (['amount_minor', 'net_minor', 'gross_minor', 'basis', 'frozen_seats'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

// ---------------------------------------------------------------------------
// FR-021 — one allowlist, every teacher-facing surface.
// ---------------------------------------------------------------------------

it('emits nothing outside the allowlist from any resource in this module', function (): void {
    $allowed = array_merge(
        TeacherFieldAllowlist::STATEMENT,
        TeacherFieldAllowlist::UNIT,
        TeacherFieldAllowlist::PERIOD,
        TeacherFieldAllowlist::AUDIT,
    );

    $resources = Finder::create()
        ->files()
        ->in(app_path('Modules/Settlement/Http/Resources'))
        ->name('*.php');

    // The keys a Resource writes are the left-hand side of its `=>` pairs. This
    // is a shallower check than walking a live payload — StatementPayloadTest
    // does that — but it covers the resources no endpoint returns yet, which is
    // exactly where a forbidden field would sit unnoticed until it did.
    $offenders = [];

    foreach ($resources as $file) {
        preg_match_all("/^\s+'([a-z_]+)' =>/m", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            if (! in_array($key, $allowed, true)) {
                $offenders[] = $file->getBasename('.php').' → '.$key;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('forbids every name the student\'s money goes by, at any depth', function (): void {
    // The two lists must not overlap: a key in both would be allowed by one rule
    // and refused by another, and which one wins is a detail of iteration order.
    $overlap = array_intersect(
        TeacherFieldAllowlist::FORBIDDEN,
        array_merge(
            TeacherFieldAllowlist::STATEMENT,
            TeacherFieldAllowlist::UNIT,
            TeacherFieldAllowlist::PERIOD,
            TeacherFieldAllowlist::AUDIT,
        ),
    );

    expect($overlap)->toBe([]);
});

// ---------------------------------------------------------------------------
// FR-034 — the audit log, filtered so neither context sees the other.
// ---------------------------------------------------------------------------

it('shows a platform auditor this context\'s acts and none of the billing context\'s', function (): void {
    Queue::fake();

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $teacher = TeacherProfile::factory()
        ->create(['user_id' => $owner->getKey()]);

    $admin = $this->addWorkspaceMember($workspace, Roles::TENANT_OWNER);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->setCurrentWorkspace($workspace, $admin);

    Sanctum::actingAs($admin);

    // One act from each context, into the SAME activity_log table. That shared
    // table is the whole reason FR-034 exists.
    app(RecordDeduction::class)
        ->handle($teacher, 2_500, 'غياب غير مبرَّر', $admin);

    activity()
        ->performedOn(Order::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
            'amount' => 199.00,
        ]))
        ->log('order.approved');

    $payload = $this->getJson('/api/v1/admin/settlement/audit')->assertOk()->json();

    expect($payload['data'])->toHaveCount(1)
        ->and($payload['data'][0]['event'])->toBe('settlement.deduction.recorded')
        ->and($payload['data'][0]['subject_type'])->toBe('ledger_entry')
        ->and($payload['data'][0]['actor_name'])->toBe($admin->name)
        ->and($payload['data'][0]['properties']['reason'])->toBe('غياب غير مبرَّر');

    // The property bag the shared trait stamps on every entry in the product
    // carries an autoincrement id. It does not leave the server.
    expect(settlementPayloadKeys($payload['data']))->not->toContain('workspace_id');
});

it('refuses the audit to a teacher who may read their own statement', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // The owner holds SETTLEMENT_STATEMENT_VIEW and every other teaching
    // permission. Reading who decided what about whose pay is a platform
    // question, and SETTLEMENT_AUDIT_VIEW reaches super-admin alone.
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/admin/settlement/audit')->assertForbidden();
});
