<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CancelSubscription;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Filament\Resources\SubscriptionResource;
use App\Modules\Payments\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| ⛔ `CancelSubscription` WAS BUILT, TESTED, AND UNREACHABLE.
|
| Its only production entrance was `POST /admin/subscriptions/{uuid}/cancel`,
| which no file under `frontend/src` called — and which was deleted on 2026-09-05
| once this screen replaced it — and there was no platform-wide LIST
| of subscriptions anywhere either, so even by hand an officer had no way to find
| a uuid to send it. The product had no way to cancel a subscription and return
| its money, while `ReversePayment → PaymentReversed → ReverseReferralAward +
| ReevaluateOnReversal` sat live behind that door.
|
| `SubscriptionCancelTest` walks the chain the Action performs. This file is about
| the SCREEN: who may open it, what it lists, and that the button is wired to the
| Action rather than to the columns.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->plan = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'duration_days' => 30,
        'price_minor' => 30_000,
    ]);

    $this->buyer = User::factory()->create(['last_workspace_id' => null]);
    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

/**
 * ⚠️ NAMED FOR THIS FILE. Pest puts every test file's helpers in one global
 * namespace, so `boughtSubscription()` — which `SubscriptionExpiryTest` already
 * declares — is a fatal redeclare the moment both files run in one process. It
 * passes when the file is run alone, which is exactly when nobody notices.
 */
function panelSubscription(): Subscription
{
    $order = app(PurchaseSubscription::class)->handle(test()->buyer, (string) test()->plan->uuid);
    app(ApproveOrder::class)->handle($order, test()->officer);

    return Subscription::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();
}

function asPanelOfficer(): void
{
    test()->actingAs(test()->officer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('opens for the finance officer and for nobody with a tenant role', function (): void {
    /*
    | ⚠️ `canViewAny()` AND NOT `SubscriptionPolicy::view`. That policy admits the
    | SUBSCRIBER and the selling teacher, both correctly, for one row over the API
    | — and a Filament LIST never consults the row policy, so either of them
    | admitted here would read every subscription on the platform with a cancel
    | button beside each.
    */
    $this->actingAs($this->owner);
    expect(SubscriptionResource::canViewAny())->toBeFalse();

    $this->actingAs($this->buyer);
    expect(SubscriptionResource::canViewAny())->toBeFalse();

    $this->actingAs($this->officer);
    expect(SubscriptionResource::canViewAny())->toBeTrue();
});

it('lists every workspace and not the officer own one', function (): void {
    /*
    | ⚠️ `WorkspaceContext::id()` FALLS BACK TO `users.last_workspace_id` FOR A
    | PLATFORM OFFICER TOO, so a scoped list quietly shows one arbitrary teacher's
    | subscriptions as though they were the platform's. A one-workspace fixture
    | cannot see it, which is why there are two here and why the officer belongs
    | to one of them.
    */
    $mine = panelSubscription();

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);
    $otherPlan = Plan::factory()->create([
        'workspace_id' => $other->getKey(),
        'duration_days' => 30,
        'price_minor' => 20_000,
    ]);
    $otherBuyer = User::factory()->create(['last_workspace_id' => null]);
    $order = app(PurchaseSubscription::class)->handle($otherBuyer, (string) $otherPlan->uuid);
    app(ApproveOrder::class)->handle($order, $this->officer);
    $theirs = Subscription::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    $this->officer->forceFill(['last_workspace_id' => $this->workspace->getKey()])->save();

    asPanelOfficer();

    Livewire::test(ListSubscriptions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine, $theirs]);
});

it('cancels through the Action and reverses the payment with it', function (): void {
    $subscription = panelSubscription();

    asPanelOfficer();

    Livewire::test(ListSubscriptions::class)
        ->callAction(
            TestAction::make('cancel')->table($subscription),
            ['reason' => 'طلب الطالب استرداد المبلغ'],
        )
        ->assertHasNoActionErrors();

    /*
    | ⚠️ THE REVERSAL IS THE ASSERTION, NOT THE STATUS. Writing `status` from the
    | screen would satisfy the first half and leave the money captured — and the
    | Action's conditional UPDATE, which is what stops two taps reversing one
    | payment twice, would have been bypassed entirely.
    */
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(PaymentTransaction::query()
            ->withoutWorkspaceScope()
            ->where('order_id', $subscription->order_id)
            ->value('status'))->toBe(PaymentStatus::Reversed);
});

it('refuses to cancel without a reason', function (): void {
    // The Action throws on a blank reason; the form asks for it first so the
    // refusal is a field error rather than a red toast after the click. FR-039
    // wants the reason recorded, and a nullable column is one caller away from an
    // audit trail of blanks.
    $subscription = panelSubscription();

    asPanelOfficer();

    Livewire::test(ListSubscriptions::class)
        ->callAction(TestAction::make('cancel')->table($subscription), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('offers no cancel button on a subscription that is already over', function (): void {
    // Not a second decision: the Action refuses a non-active row anyway, and a
    // button that always answers «هذا الاشتراك غير سارٍ أصلاً» reads as broken.
    $subscription = panelSubscription();

    app(CancelSubscription::class)
        ->handle($subscription, 'أُلغي سلفاً');

    asPanelOfficer();

    Livewire::test(ListSubscriptions::class)
        ->assertActionHidden(TestAction::make('cancel')->table($subscription->refresh()));
});

it('creates nothing and deletes nothing from this screen', function (): void {
    // A subscription is bought — buying it writes an order, a payment and a
    // period at once — and deleting the row would take away the student's record
    // of what they paid for. Repeated on the Resource because `BasePolicy::before()`
    // waves a super admin past every policy method, and a super admin is exactly
    // who stands here.
    $subscription = panelSubscription();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    expect(SubscriptionResource::canCreate())->toBeFalse()
        ->and(SubscriptionResource::canDelete($subscription))->toBeFalse()
        ->and(SubscriptionResource::canEdit($subscription))->toBeFalse();
});

/*
| ⛔ AND WHO MAY UNDO A PURCHASE IS ASKED HERE NOW.
|
| `SubscriptionCancelTest` asked it of the route, which is gone — so the panel
| action's `visible(fn () => … Gate::allows('cancel', $record))` is the ONLY
| reader of `SubscriptionPolicy::cancel()` left in the tree. A policy nothing
| exercises is the shape this repository has already paid for three times.
|
| ⚠️ HIDDEN, NOT DISABLED, AND THAT IS THE RIGHT DIRECTION HERE. The two who are
| refused have no path to the capability at all — a teacher does not become able
| to reverse a payment by asking — so a visible-but-dead button would only invite
| the question. (The opposite call from the article `status` field, where the
| writer must still SEE whether their own post is live.)
*/
it('offers the undo to the officer alone', function (): void {
    $subscription = panelSubscription();

    // Money leaving the platform is not a decision either party to the lesson
    // takes alone — the same reason `BILLING_PURCHASE_APPROVE` guards the
    // approval this undoes.
    $this->actingAs($this->buyer);
    expect(Gate::allows('cancel', $subscription))->toBeFalse();

    $this->actingAs($this->owner);
    expect(Gate::allows('cancel', $subscription))->toBeFalse();

    asPanelOfficer();
    expect(Gate::allows('cancel', $subscription))->toBeTrue();

    // And the button follows the policy rather than restating it. Only the
    // officer's side of this can be rendered at all — `canViewAny()` refuses the
    // other two the screen itself, which is the first of the two locks.
    Livewire::test(ListSubscriptions::class)
        ->assertActionVisible(TestAction::make('cancel')->table($subscription));
});
