<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Events\ReceiptRejected;
use App\Modules\Payments\Events\ReceiptUploaded;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Roles;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| FR-020 · FR-024 — the declared path, and the trail it leaves.
|
| ⚠️ A "DECLARED PATH" IS THE FORBIDDEN TRANSITIONS AS MUCH AS THE ALLOWED ONES.
| Asserting only that the three events fire would leave every backwards move
| open: a receipt uploaded onto an order already approved replaces the document
| the approver actually read, and the trail then shows a decision taken on a file
| that arrived after it.
|
| The audit assertions CREATE the trail rather than extend it: `RejectOrder` and
| `UploadPaymentReceipt` used no activity log at all, so the record showed every
| acceptance and no refusal and no submission.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

function uploadReceipt(Order $order, PaymentMethod $method = PaymentMethod::BankTransfer): Order
{
    return app(UploadPaymentReceipt::class)->handle(
        $order,
        UploadedFile::fake()->image('transfer.png'),
        test()->student,
        $method,
        '203.0.113.9',
        'Mozilla/5.0 (Test)',
    );
}

// The three events -----------------------------------------------------------

it('announces the receipt at each of its three moments', function (): void {
    Event::fake([ReceiptUploaded::class, ReceiptApproved::class, ReceiptRejected::class]);

    uploadReceipt($this->order);

    Event::assertDispatched(ReceiptUploaded::class);

    app(ApproveOrder::class)->handle($this->order->refresh(), $this->owner);

    Event::assertDispatched(ReceiptApproved::class);
    Event::assertNotDispatched(ReceiptRejected::class);
});

it('announces a rejection with the reason attached to it', function (): void {
    Event::fake([ReceiptRejected::class]);

    uploadReceipt($this->order);

    app(RejectOrder::class)->handle($this->order->refresh(), $this->owner, 'المبلغ لا يطابق');

    // The reason travels WITH the event: a rejection the payer cannot read is a
    // rejection they will repeat, with the same transfer.
    Event::assertDispatched(
        ReceiptRejected::class,
        fn (ReceiptRejected $event): bool => $event->reason === 'المبلغ لا يطابق',
    );
});

// The declared path ----------------------------------------------------------

it('moves an uploaded receipt to under review and records the method', function (): void {
    $order = uploadReceipt($this->order, PaymentMethod::MobileWallet);

    expect($order->status)->toBe('under_review')
        ->and($order->metadata['method'] ?? null)->toBe('mobile_wallet');
});

it('refuses a receipt on an order that has already been decided', function (): void {
    uploadReceipt($this->order);

    app(ApproveOrder::class)->handle($this->order->refresh(), $this->owner);

    expect(fn () => uploadReceipt($this->order->refresh()))
        ->toThrow(DomainException::class);
});

it('refuses a receipt claiming it was paid by gateway', function (): void {
    // A gateway payment mints its own transaction and needs no receipt at all,
    // so accepting the claim would let a payer label a wire as a settled card.
    expect(fn () => uploadReceipt($this->order, PaymentMethod::Gateway))
        ->toThrow(DomainException::class);
});

it('refuses a second decision after either one', function (): void {
    uploadReceipt($this->order);

    app(RejectOrder::class)->handle($this->order->refresh(), $this->owner, 'غير مطابق');

    expect(fn () => app(ApproveOrder::class)->handle($this->order->refresh(), $this->owner))
        ->toThrow(DomainException::class);
});

// The trail ------------------------------------------------------------------

it('records the terminal behind every step of the receipt', function (): void {
    uploadReceipt($this->order);

    app(ApproveOrder::class)->handle($this->order->refresh(), $this->owner, '198.51.100.4', 'Firefox/Test');

    $descriptions = Activity::query()->pluck('description')->all();

    expect($descriptions)->toContain('receipt.uploaded')
        ->and($descriptions)->toContain('approved');

    $uploaded = Activity::query()->where('description', 'receipt.uploaded')->firstOrFail();
    $approved = Activity::query()->where('description', 'approved')->firstOrFail();

    // FR-024 — the account is not the whole answer. An operator's session on a
    // machine that is not theirs is exactly the case the address exists for.
    expect($uploaded->properties['ip_address'] ?? null)->toBe('203.0.113.9')
        ->and($uploaded->properties['method'] ?? null)->toBe('bank_transfer')
        ->and($approved->properties['ip_address'] ?? null)->toBe('198.51.100.4');
});

it('records a refusal as fully as it records an acceptance', function (): void {
    uploadReceipt($this->order);

    app(RejectOrder::class)->handle($this->order->refresh(), $this->owner, 'غير مطابق', '198.51.100.4', 'Firefox/Test');

    $rejected = Activity::query()->where('description', 'rejected')->firstOrFail();

    expect($rejected->properties['reason'] ?? null)->toBe('غير مطابق')
        ->and($rejected->properties['ip_address'] ?? null)->toBe('198.51.100.4');
});
