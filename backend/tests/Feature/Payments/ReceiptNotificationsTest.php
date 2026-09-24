<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\ReceiptApproved;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/*
| The receipt's three moments, heard by the people they concern.
|
| ⚠️ ALL THREE EVENTS FIRED SINCE 007 AND NOTHING LISTENED. So on the manual
| path — the only path in use — the officer never heard a receipt had arrived,
| and the payer never heard it was accepted or refused: a refused transfer read
| «قيد المراجعة» until they happened to open `/orders` and find the reason.
|
| Driven through the Actions on `sync`, never by faking the queue: a bare
| `Queue::fake()` swallows the queued listener and every assertion below would be
| a confident claim about an empty table.
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
});

function receiptNoticeOrder(OrderKind $kind, bool $withCourse = true): Order
{
    return Order::create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => test()->student->getKey(),
        'course_id' => $withCourse ? test()->course->getKey() : null,
        'kind' => $kind,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
}

function receiptNoticeUpload(Order $order, User $by): Order
{
    return app(UploadPaymentReceipt::class)->handle(
        $order,
        UploadedFile::fake()->image('transfer.png'),
        $by,
        PaymentMethod::BankTransfer,
    );
}

function receiptNoticesFor(User $user, NotificationType $type): Collection
{
    return Notification::query()
        ->where('recipient_user_id', $user->getKey())
        ->where('type', $type->value)
        ->get();
}

// The payer: refused ---------------------------------------------------------

it('tells the payer their receipt was refused, with the reason and the way back', function (): void {
    $order = receiptNoticeUpload(receiptNoticeOrder(OrderKind::Course), $this->student);

    app(RejectOrder::class)->handle($order->refresh(), $this->owner, 'المبلغ لا يطابق');

    $notices = receiptNoticesFor($this->student, NotificationType::ReceiptRejected);

    expect($notices)->toHaveCount(1)
        // The reason travels: a refusal the payer cannot read is one they repeat.
        ->and($notices->first()->body)->toContain('المبلغ لا يطابق')
        ->and($notices->first()->body)->toContain($this->course->title)
        // The SAME order takes a new receipt (027 · FR-032), and this is where.
        ->and($notices->first()->action_url)->toBe('/orders');
});

it('names the kind when a refused order has no course, rather than dropping the message', function (): void {
    // A required template variable left empty refuses the whole message — so a
    // store order with no course would otherwise be refused in silence.
    $order = receiptNoticeUpload(receiptNoticeOrder(OrderKind::Store, withCourse: false), $this->student);

    app(RejectOrder::class)->handle($order->refresh(), $this->owner, 'الصورة غير واضحة');

    $notices = receiptNoticesFor($this->student, NotificationType::ReceiptRejected);

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toContain(OrderKind::Store->label());
});

// The payer: accepted --------------------------------------------------------

it('tells a store buyer their receipt was accepted, and where their purchase is', function (): void {
    $order = receiptNoticeUpload(receiptNoticeOrder(OrderKind::Store, withCourse: false), $this->student);

    app(ApproveOrder::class)->handle($order->refresh(), $this->owner);

    $notices = receiptNoticesFor($this->student, NotificationType::ReceiptApproved);

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->action_url)->toBe('/store')
        // The reworded body: a store buyer holds no balance to have «added».
        ->and($notices->first()->body)->not->toContain('رصيدك');
});

it('sends a credit buyer to their balance', function (): void {
    // The event directly: approving a credit order also mints credits, whose
    // fixture is another file's subject — this one is about who is told.
    $order = receiptNoticeOrder(OrderKind::Credits);

    event(new ReceiptApproved($order, $this->owner));

    $notices = receiptNoticesFor($this->student, NotificationType::ReceiptApproved);

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->action_url)->toBe('/billing')
        ->and($notices->first()->body)->toContain($this->course->title);
});

it('says nothing extra about a course order, whose enrolment notice already speaks', function (): void {
    $order = receiptNoticeUpload(receiptNoticeOrder(OrderKind::Course), $this->student);

    app(ApproveOrder::class)->handle($order->refresh(), $this->owner);

    // One click, one message: the enrolment notice is the outcome here.
    expect(receiptNoticesFor($this->student, NotificationType::ReceiptApproved))->toHaveCount(0)
        ->and(receiptNoticesFor($this->student, NotificationType::EnrollmentCreated))->toHaveCount(1);
});

// The officer ----------------------------------------------------------------

it('tells the finance officer a receipt is waiting, and nobody else', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $order = receiptNoticeUpload(receiptNoticeOrder(OrderKind::Store, withCourse: false), $this->student);

    $notices = receiptNoticesFor($officer, NotificationType::ReceiptAwaitingReview);

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->body)->toContain($this->student->name)
        // The panel page that carries approve/refuse — `/orders` no longer does.
        ->and($notices->first()->action_url)->toBe('/admin/orders/'.$order->uuid.'/edit')
        // A finance officer exists, so the platform operator is not pinged too.
        ->and(receiptNoticesFor($admin, NotificationType::ReceiptAwaitingReview))->toHaveCount(0)
        // And the payer is not told about their own upload.
        ->and(receiptNoticesFor($this->student, NotificationType::ReceiptAwaitingReview))->toHaveCount(0);
});

it('reaches the officer for a course order too, under the permission that decides it', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    receiptNoticeUpload(receiptNoticeOrder(OrderKind::Course), $this->student);

    expect(receiptNoticesFor($officer, NotificationType::ReceiptAwaitingReview))->toHaveCount(1)
        // The teacher is the payee, not the witness (2026-09-03), so not them.
        ->and(receiptNoticesFor($this->owner, NotificationType::ReceiptAwaitingReview))->toHaveCount(0);
});

it('falls back to the super admin when no officer holds the permission', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    receiptNoticeUpload(receiptNoticeOrder(OrderKind::Store, withCourse: false), $this->student);

    expect(receiptNoticesFor($admin, NotificationType::ReceiptAwaitingReview))->toHaveCount(1);
});

it('does not tell the officer about a receipt they uploaded themselves', function (): void {
    // 024 · FR-007: the receipt arrived on WhatsApp and the officer holds it.
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $colleague = makePlatformStaff(Roles::FINANCE_ADMIN);

    receiptNoticeUpload(receiptNoticeOrder(OrderKind::Store, withCourse: false), $officer);

    expect(receiptNoticesFor($officer, NotificationType::ReceiptAwaitingReview))->toHaveCount(0)
        ->and(receiptNoticesFor($colleague, NotificationType::ReceiptAwaitingReview))->toHaveCount(1);
});

// The refusal sentence -------------------------------------------------------

// The reworded template reaches a live database ------------------------------

it('rewords the shipped «receipt approved» body on a live database, and only the shipped one', function (): void {
    $migration = require base_path('app/Modules/Notifications/Database/Migrations/2026_09_23_000600_reword_receipt_approved_for_every_kind.php');

    $row = MessageTemplate::query()
        ->where('type', NotificationType::ReceiptApproved->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    $row->forceFill(['body' => 'راجع الفريق إيصالك عن «{{ course }}» واعتمده، وأُضيف رصيدك.'])->save();
    $migration->up();
    expect($row->fresh()?->body)->not->toContain('رصيدك');

    // An operator's own wording is theirs.
    $row->forceFill(['body' => 'ADMIN_EDITED_SENTINEL'])->save();
    $migration->up();
    expect($row->fresh()?->body)->toBe('ADMIN_EDITED_SENTINEL');
});

it('refuses a stranger\'s upload in Arabic', function (): void {
    $stranger = User::factory()->create();

    expect(fn () => receiptNoticeUpload(receiptNoticeOrder(OrderKind::Course), $stranger))
        ->toThrow(DomainException::class, 'لا يمكنك رفع إيصال');
});
