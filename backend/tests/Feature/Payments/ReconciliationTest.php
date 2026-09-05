<?php

declare(strict_types=1);

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Events\CreditExpired;
use App\Modules\Payments\Jobs\ExpireCreditLotsJob;
use App\Modules\Payments\Jobs\NotifyDormantBalancesJob;
use App\Modules\Payments\Jobs\ReconcileCreditBalancesJob;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditReconciliationRun;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Phase 12 — the three sweeps that watch what the request path cannot.
|
|   · RECONCILIATION asks whether the books still add up, and the point of the
|     test is the check the naive version is BLIND to: a session that was never
|     charged leaves the balance and its entries in perfect agreement, because
|     both sides are written by the same path in the same transaction;
|   · EXPIRY is built and switched off. What is asserted is that it finds nothing
|     while `expires_at` is null, and that it writes off through the LEDGER when
|     a date exists — a balance moved behind the ledger's back is the discrepancy
|     the sweep above would report every night afterwards;
|   · DORMANCY is a reminder and never a forfeiture. The credits are still there
|     after it runs, and it does not run again tomorrow.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = billingCourse($this->workspace);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();
});

function lastRun(): ?CreditReconciliationRun
{
    return CreditReconciliationRun::query()->latest('ran_at')->first();
}

// Reconciliation ---------------------------------------------------------------

it('records a run even when it finds nothing', function (): void {
    grantCredits($this->balance, 4, 'clean');

    app(ReconcileCreditBalancesJob::class)->handle();

    // ⚠️ THE EMPTY RUN IS THE POINT. Derived from findings alone, a clean
    // platform and a sweep that stopped last Tuesday are the same empty list —
    // and the second is the one worth waking someone for.
    expect(lastRun())->not->toBeNull()
        ->and(lastRun()->findings_count)->toBe(0)
        ->and(lastRun()->balances_checked)->toBeGreaterThan(0);
});

it('catches a balance moved behind the ledger', function (): void {
    grantCredits($this->balance, 4, 'tampered');

    // The failure the "one writer" rule exists to prevent, so the only one
    // nobody would be watching for.
    DB::table('credit_balances')->where('id', $this->balance->getKey())
        ->update(['remaining_credits' => 9]);

    app(ReconcileCreditBalancesJob::class)->handle();

    $findings = collect(lastRun()->findings ?? []);

    expect($findings->pluck('check')->all())->toContain('ledger_sum')
        ->and($findings->firstWhere('check', 'ledger_sum')['expected'])->toBe(4)
        ->and($findings->firstWhere('check', 'ledger_sum')['actual'])->toBe(9);
});

it('catches a charged session that debited only some of its seats', function (): void {
    $second = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 4);

    foreach ([$this->student, $second] as $person) {
        DB::table('session_bookings')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->getKey(),
            'class_session_id' => $session->getKey(),
            'student_user_id' => $person->getKey(),
            'status' => 'booked',
            'is_billable' => true,
            'booked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('class_sessions')->where('id', $session->getKey())->update([
        'delivered_at' => now(),
        'charged_at' => now(),
    ]);

    // One of the two debited — the worker died between them. Both the balance
    // and its entries agree perfectly, which is exactly why the first check sees
    // nothing here.
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $this->balance,
        type: CreditTransactionType::Consume,
        credits: -1,
        sourceType: 'class_session',
        sourceId: (int) $session->getKey(),
    ));

    app(ReconcileCreditBalancesJob::class)->handle();

    $findings = collect(lastRun()->findings ?? []);
    $seatFinding = $findings->firstWhere('check', 'session_seats');

    expect($seatFinding)->not->toBeNull()
        ->and($seatFinding['expected'])->toBe(2)
        ->and($seatFinding['actual'])->toBe(1)
        // And the blind check stayed silent, which is the claim being made.
        ->and($findings->pluck('check')->all())->not->toContain('ledger_sum');
});

it('reads the last run back through a platform permission only', function (): void {
    app(ReconcileCreditBalancesJob::class)->handle();

    Sanctum::actingAs($this->owner);

    // The workspace owner is not a platform operator, whatever they own.
    $this->getJson('/api/v1/admin/billing/reconciliation')->assertForbidden();

    /*
    | ⚠️ `billing.collection.view`, AND THIS LINE HELD `billing.pricing.manage`
    | UNTIL 2026-09-05 — a READ of the platform's ledger behind the door for
    | EDITING the platform's cut. Its twin `PaymentReconciliationController` asks
    | the permission below, so the officer who opened the payments sweep every
    | morning was refused this one, and granting them this one handed over the six
    | pricing keys with it. The test agreed with the controller, which is why
    | nothing failed: two spellings of one mistake.
    */
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $operator = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $operator->givePermissionTo(Permissions::BILLING_COLLECTION_VIEW);
    $this->setCurrentWorkspace($this->workspace, $operator);

    Sanctum::actingAs($operator);

    $this->getJson('/api/v1/admin/billing/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.findings_count', 0)
        // Half the answer: an empty list from a sweep that has not run in a week
        // means something entirely different.
        ->assertJsonPath('data.ran_at', fn (?string $at): bool => $at !== null);
});

// Expiry — built, and switched off ---------------------------------------------

it('expires nothing while credits carry no expiry date (Q-5)', function (): void {
    Event::fake([CreditExpired::class]);

    grantCredits($this->balance, 5, 'permanent');

    app(ExpireCreditLotsJob::class)->handle(app(CreditLedger::class));

    expect($this->balance->refresh()->remaining_credits)->toBe(5);

    Event::assertNotDispatched(CreditExpired::class);
});

it('writes off an expired lot through the ledger, and takes it once', function (): void {
    Event::fake([CreditExpired::class]);

    grantCredits($this->balance, 3, 'dated', CarbonImmutable::now()->addDay());
    grantCredits($this->balance, 2, 'undated');

    $this->travel(2)->days();

    app(ExpireCreditLotsJob::class)->handle(app(CreditLedger::class));

    // ⚠️ THE UNDATED LOT IS UNTOUCHED, and that is the assertion the write-off
    // path was designed around: posting the expiry as an ordinary negative
    // movement would have let the drawer take the same three credits again, out
    // of a lot that has not expired.
    expect($this->balance->refresh()->remaining_credits)->toBe(2)
        ->and((int) CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))->toBe(2);

    // Through the ledger, so the entries still sum to the balance — asserted by
    // the sweep itself rather than by re-adding them here.
    app(ReconcileCreditBalancesJob::class)->handle();

    expect(lastRun()->findings_count)->toBe(0);

    // And a second pass claims nothing.
    app(ExpireCreditLotsJob::class)->handle(app(CreditLedger::class));

    expect($this->balance->refresh()->remaining_credits)->toBe(2);

    Event::assertDispatchedTimes(CreditExpired::class, 1);
});

// Dormancy — a reminder, never an expiry ---------------------------------------

it('reminds the holder of an untouched balance once, and forfeits nothing', function (): void {
    grantCredits($this->balance, 6, 'dormant');

    // Thirteen months of silence, one past the platform's twelve.
    DB::table('credit_balances')->where('id', $this->balance->getKey())
        ->update(['last_transaction_at' => now()->subMonths(13)]);

    app(NotifyDormantBalancesJob::class)->handle(
        app(BillingSettings::class),
        app(DispatchNotification::class),
    );

    $sent = fn (): int => Notification::query()
        ->where('recipient_user_id', $this->student->getKey())
        ->where('type', NotificationType::CreditBalanceDormant->value)
        ->count();

    expect($sent())->toBe(1)
        // ⚠️ NOT ONE CREDIT MOVED. Q-8 is a reminder; a sweep that confiscated
        // dormant credits would be the platform inventing an expiry it never
        // sold.
        ->and($this->balance->refresh()->remaining_credits)->toBe(6);

    // And it does not run again tomorrow. A predicate on `last_transaction_at`
    // alone would re-send this every night for ever, because the notice itself
    // moves nothing.
    app(NotifyDormantBalancesJob::class)->handle(
        app(BillingSettings::class),
        app(DispatchNotification::class),
    );

    expect($sent())->toBe(1);
});

it('says nothing about a balance that is still being used', function (): void {
    grantCredits($this->balance, 6, 'active');

    app(NotifyDormantBalancesJob::class)->handle(
        app(BillingSettings::class),
        app(DispatchNotification::class),
    );

    expect(Notification::query()->where('type', NotificationType::CreditBalanceDormant->value)->count())
        ->toBe(0);
});

it('reminds again after the holder comes back and goes quiet once more', function (): void {
    grantCredits($this->balance, 6, 'first');

    DB::table('credit_balances')->where('id', $this->balance->getKey())
        ->update(['last_transaction_at' => now()->subMonths(13)]);

    app(NotifyDormantBalancesJob::class)->handle(
        app(BillingSettings::class),
        app(DispatchNotification::class),
    );

    // A movement clears the mark, because the movement is the moment it stopped
    // being true — not the sweep, which would only notice the next night.
    grantCredits($this->balance->refresh(), 2, 'returned');

    expect($this->balance->refresh()->notified_dormant_at)->toBeNull();

    DB::table('credit_balances')->where('id', $this->balance->getKey())
        ->update(['last_transaction_at' => now()->subMonths(13)]);

    app(NotifyDormantBalancesJob::class)->handle(
        app(BillingSettings::class),
        app(DispatchNotification::class),
    );

    expect(Notification::query()->where('type', NotificationType::CreditBalanceDormant->value)->count())
        ->toBe(2);
});
