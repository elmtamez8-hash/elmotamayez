<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\BuildCollectionReport;
use App\Modules\Payments\Actions\SettleRefundDue;
use App\Modules\Payments\Data\CollectionFilter;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| «تمّ الردّ» — the queue `refund_due` never had.
|
| ⛔ The store writes `refund_due` (the buyer's refund inside the window, a paid
| order whose last copy went before approval) and nothing read it: the panel's
| status filter could not select it, no button moved it, and the capture stayed
| `captured` — so the collection report went on counting money the platform had
| promised to give back.
|
| ⚠️ The officer owns ANOTHER workspace (`last_workspace_id` stamped): a scoped
| claim would hit zero rows on this teacher's order and read «settled already» —
| the 024 layer a one-workspace fixture passes green over.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'media_asset_id' => MediaAsset::factory()->create(['workspace_id' => $this->workspace->getKey()])->getKey(),
    ]);

    $this->buyer = User::factory()->create();
    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray(['item_uuid' => $item->uuid]));

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    [$decoy] = $this->createWorkspaceWithOwner();
    $this->officer->forceFill(['last_workspace_id' => $decoy->getKey()])->save();

    app(ApproveOrder::class)->handle(Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id), $this->officer);
    app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    $this->order = Order::query()->withoutWorkspaceScope()->findOrFail($purchase->order_id);

    $this->actingAs($this->officer);
    app()->forgetInstance(WorkspaceContext::class);
});

function settledCapture(Order $order): PaymentTransaction
{
    return PaymentTransaction::query()->withoutWorkspaceScope()->where('order_id', $order->getKey())->sole();
}

/** @return list<string> the payment statuses the collection report shows today. */
function collectedStatusesToday(): array
{
    $report = app(BuildCollectionReport::class)->handle(new CollectionFilter(
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    ));

    return array_map(fn (array $row): string => (string) $row['key'], $report['summary']['by_status']);
}

it('starts from a real refund: the order is due back and the payment still reads collected', function (): void {
    expect($this->order->status)->toBe('refund_due')
        ->and(settledCapture($this->order)->status)->toBe(PaymentStatus::Captured)
        ->and(collectedStatusesToday())->toBe([PaymentStatus::Captured->value]);
});

it('lets the officer find the order by its status', function (): void {
    Livewire::test(ListOrders::class)
        ->filterTable('status', 'refund_due')
        ->assertCanSeeTableRecords([$this->order])
        ->assertTableActionVisible('settleRefund', $this->order->getKey());
});

it('records the refund: the payment is reversed, the order closes and the report stops counting it', function (): void {
    Livewire::test(ListOrders::class)
        ->callTableAction('settleRefund', $this->order->getKey(), ['reason' => 'تحويل بنكي رقم 12345'])
        ->assertHasNoTableActionErrors();

    expect(settledCapture($this->order)->status)->toBe(PaymentStatus::Reversed)
        ->and(settledCapture($this->order)->failure_reason)->toBe('تحويل بنكي رقم 12345')
        ->and($this->order->refresh()->status)->toBe('cancelled')
        ->and(collectedStatusesToday())->toBe([PaymentStatus::Reversed->value]);
});

it('refuses a second settlement with a sentence and reverses nothing twice', function (): void {
    app(SettleRefundDue::class)->handle($this->order, $this->officer, 'الأولى');

    expect(fn () => app(SettleRefundDue::class)->handle($this->order->refresh(), $this->officer, 'الثانية'))
        ->toThrow(DomainException::class);

    expect(settledCapture($this->order)->failure_reason)->toBe('الأولى');
});

it('refuses an officer past their two-factor deadline and changes nothing', function (): void {
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    $this->actingAs($this->officer->refresh());

    Livewire::test(ListOrders::class)
        ->callTableAction('settleRefund', $this->order->getKey(), ['reason' => 'سبب']);

    expect($this->order->refresh()->status)->toBe('refund_due')
        ->and(settledCapture($this->order)->status)->toBe(PaymentStatus::Captured);
});

it('refuses the settlement to the teacher, who is paid from this money', function (): void {
    expect(Gate::forUser($this->teacher)->allows('reverse', $this->order))->toBeFalse()
        ->and(Gate::forUser($this->officer)->allows('reverse', $this->order))->toBeTrue();
});
