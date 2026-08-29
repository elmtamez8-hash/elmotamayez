<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Jobs\ExpireSubscriptionsJob;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AccountStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| FR-027 — the month runs out, access stops, and the student was warned (T096).
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->course->forceFill(['status' => 'published'])->save();

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->approver = makePlatformStaff(Roles::FINANCE_ADMIN);
});

function boughtSubscription(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->approver);

    return Subscription::query()->withoutWorkspaceScope()->firstOrFail();
}

/** Move a live subscription's window without going through a freeze. */
function endSubscriptionOn(Subscription $subscription, CarbonImmutable $day): Subscription
{
    $subscription->forceFill([
        'ends_on' => $day,
        'effective_ends_on' => $day,
    ])->save();

    return $subscription->refresh();
}

it('leaves a subscription alone on the very LAST day it was sold', function (): void {
    /*
    | ⚠️ THE ONE-DAY BUG THIS WHOLE COLUMN IS WRITTEN AGAINST. `effective_ends_on`
    | is a DATE that SQLite stores as `2026-09-30 00:00:00`, so a sweep written
    | `<= today` expires it a day early on one engine and on time on the other.
    | The day it eats is the last day the student paid for.
    */
    $subscription = endSubscriptionOn(boughtSubscription(), CarbonImmutable::today());

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('expires it the day after, and shuts the access it opened', function (): void {
    $subscription = endSubscriptionOn(boughtSubscription(), CarbonImmutable::today()->subDay());

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);

    // ⚠️ `status`, NOT `expires_at`. Nothing in this tree reads `expires_at` as a
    // gate, so an enrolment left `active` with a date in the past is permanent
    // access sold for one month.
    expect(Enrollment::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $this->buyer->getKey())
        ->value('status'))->toBe('expired');

    // And the door it opened is shut, asked the way every caller asks it.
    expect(app(AccountStanding::class)->isWithheld($this->buyer, (int) $this->course->getKey()))
        ->toBeTrue();
});

it('never touches a course the student bought outright', function (): void {
    /*
    | ⚠️ `EnrollStudent` IS `firstOrCreate`. A student who already owns this
    | course gets their existing, open-ended enrolment back — and closing it when
    | the subscription ends would revoke, a month later and without a word, access
    | they paid for once and for good.
    */
    $existing = $this->createEnrollment($this->workspace, $this->course, $this->buyer);

    $subscription = endSubscriptionOn(boughtSubscription(), CarbonImmutable::today()->subDay());

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($existing->refresh()->status)->toBe('active');
});

it('warns the student once, and only once, inside the notice window', function (): void {
    PlatformSettings::set('subscription.expiring_notice_days', 3);

    $subscription = endSubscriptionOn(boughtSubscription(), CarbonImmutable::today()->addDays(2));

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));
    // A second pass the same night — a manual invocation beside the schedule, or
    // a retry. A predicate on the date alone re-sends every night to the student
    // least likely to want it.
    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect(Notification::query()
        ->where('recipient_user_id', $this->buyer->getKey())
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->count())->toBe(1)
        ->and($subscription->refresh()->expiring_notified_at)->not->toBeNull();

    PlatformSettings::flush();
});

it('says nothing to a subscription that is not near its end', function (): void {
    PlatformSettings::set('subscription.expiring_notice_days', 3);

    endSubscriptionOn(boughtSubscription(), CarbonImmutable::today()->addDays(20));

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect(Notification::query()
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->count())->toBe(0);

    PlatformSettings::flush();
});

it('reads the EXTENDED end date, not the one the plan sold', function (): void {
    /*
    | A freeze moves the real end. Telling a student to renew before a deadline
    | that has already been extended for them is a message the screen beside it
    | contradicts — which is why both read `effective_ends_on`.
    */
    PlatformSettings::set('subscription.expiring_notice_days', 3);

    $subscription = boughtSubscription();

    $subscription->forceFill([
        'ends_on' => CarbonImmutable::today()->addDay(),
        'effective_ends_on' => CarbonImmutable::today()->addDays(20),
    ])->save();

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect(Notification::query()
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->count())->toBe(0)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);

    PlatformSettings::flush();
});

it('sends nothing at all when an operator sets the notice to zero days', function (): void {
    PlatformSettings::set('subscription.expiring_notice_days', 0);

    endSubscriptionOn(boughtSubscription(), CarbonImmutable::today());

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect(Notification::query()
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->count())->toBe(0);

    PlatformSettings::flush();
});

it('has a seeded template, or the notice is dropped in silence', function (): void {
    /*
    | ⚠️ THE FOURTH RUNTIME CATALOGUE IN THIS TREE TO NEED A BACKFILL MIGRATION.
    | `TemplateRenderer` refuses a missing row and `DispatchNotification` logs
    | rather than failing — so without the template the job stamps
    | `expiring_notified_at`, reports success and delivers nothing, permanently:
    | the stamp is one-way, so the notice can never be re-sent even afterwards.
    |
    | ⚠️ AND THE ROW IS DELETED FIRST. `tests/Pest.php` seeds the templates before
    | every case, so asserting the row is present proves only that Pest.php ran.
    */
    MessageTemplate::query()
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->delete();

    $migration = require base_path(
        'app/Modules/Payments/Database/Migrations/2026_08_29_004200_backfill_subscription_notification_template.php',
    );

    $migration->up();

    expect(MessageTemplate::query()
        ->where('type', NotificationType::SubscriptionExpiring->value)
        ->exists())->toBeTrue();
});

it('compares the end date with `<`, which is the half no local engine can check', function (): void {
    /*
    | ⚠️ MEASURED, NOT ASSUMED: breaking the sweep to `<= today` leaves THIS
    | WHOLE FILE GREEN. Eloquent writes the date-cast attribute through the
    | model's datetime format, so SQLite stores `2026-08-29 00:00:00` and
    | `'2026-08-29 00:00:00' <= '2026-08-29'` is FALSE — the broken form behaves
    | exactly like the correct one. On MySQL the column is a real DATE, the
    | comparison is TRUE, and every subscription dies on the morning of the last
    | day its owner paid for.
    |
    | So the guard cannot be an outcome here; it is a PIN ON THE MECHANISM. Same
    | shape as the coupon cap's concurrency pin, and for the same reason: the
    | environment that fails is the one nobody runs the suite in.
    */
    endSubscriptionOn(boughtSubscription(), CarbonImmutable::today());

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'effective_ends_on')) {
            $statements[] = $query->sql;
        }
    });

    app(ExpireSubscriptionsJob::class)->handle(app(DispatchNotification::class));

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect($sql)->not->toContain('"effective_ends_on" <= ');
    }
});
