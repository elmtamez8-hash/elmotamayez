<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\ManageExamModeWindow;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\SetCreditLimit;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Http\Resources\BillingAuditEntryResource;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\BillingAuditSubjects;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Models\ActivityEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| SC-008 — five financial acts leave five entries, each naming who, when, and
| from where.
|
| ⚠️ "FROM WHERE" IS THE HALF THIS PHASE ADDED (FR-024), AND IT IS NOT A DETAIL.
| An account is what a compromised session borrows; the terminal a decision came
| from is what lets an investigator see four approvals arriving from one machine
| at three in the morning. Before this, `ApproveOrder` recorded a name and a time
| and `RejectOrder` recorded nothing at all.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);

    // FR-048 — no recorded agreement, no ceiling to move. Recorded here because
    // this file is about the TRAIL, and a refusal would leave four entries.
    TermsConsent::factory()->create([
        'user_id' => $this->student->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);
});

function auditableOrder(): Order
{
    $test = test();

    return Order::create([
        'workspace_id' => $test->workspace->getKey(),
        'user_id' => $test->student->getKey(),
        'course_id' => $test->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
}

it('records five decisions with their actor, moment and terminal', function (): void {
    $approved = auditableOrder();
    $rejected = auditableOrder();

    app(UploadPaymentReceipt::class)->handle(
        $approved,
        UploadedFile::fake()->image('t.png'),
        $this->student,
        PaymentMethod::BankTransfer,
        '203.0.113.9',
        'Mozilla/5.0',
    );

    $this->actingAs($this->owner);

    app(ApproveOrder::class)->handle($approved->refresh(), $this->owner, '198.51.100.4', 'Firefox');
    app(RejectOrder::class)->handle($rejected, $this->owner, 'غير مطابق', '198.51.100.4', 'Firefox');

    app(SetCreditLimit::class)->handle($this->balance, 2, 'مراجعة يدوية', $this->owner);

    app(ManageExamModeWindow::class)->handle(
        $this->workspace,
        now()->toImmutable(),
        now()->addDays(7)->toImmutable(),
        $this->owner,
    );

    $entries = ActivityEntry::query()
        ->whereIn('subject_type', BillingAuditSubjects::types())
        ->get();

    expect($entries)->toHaveCount(5);

    // Every entry answers all three questions. An entry with a description and
    // nothing else is a line in a log, not a record of a decision.
    foreach ($entries as $entry) {
        expect($entry->description)->not->toBe('')
            ->and($entry->created_at)->not->toBeNull()
            ->and($entry->subject_type)->not->toBeNull();
    }

    $receipt = $entries->firstWhere('description', 'receipt.uploaded');
    $approval = $entries->firstWhere('description', 'approved');
    $rejection = $entries->firstWhere('description', 'rejected');

    expect($receipt?->properties['ip_address'] ?? null)->toBe('203.0.113.9')
        ->and($approval?->properties['ip_address'] ?? null)->toBe('198.51.100.4')
        ->and($rejection?->properties['ip_address'] ?? null)->toBe('198.51.100.4')
        // The approver is a person and is named. The uploader is the payer.
        ->and($approval?->causer_id)->toBe($this->owner->getKey());
});

it('exposes the terminal as a named field rather than buried in properties', function (): void {
    $order = auditableOrder();

    $this->actingAs($this->owner);
    app(ApproveOrder::class)->handle($order, $this->owner, '198.51.100.4', 'Firefox/Test');

    $entry = ActivityEntry::query()->where('description', 'approved')->firstOrFail();

    $payload = app(BillingAuditEntryResource::class, ['resource' => $entry])
        ->toArray(request());

    // Lifted out of the free-form bag and named: a reader should not have to know
    // which Action happened to spell it which way.
    expect($payload['ip_address'])->toBe('198.51.100.4')
        ->and($payload['user_agent'])->toBe('Firefox/Test')
        ->and($payload['subject_type'])->toBe('order')
        ->and($payload['actor_name'])->toBe($this->owner->name)
        // And the autoincrement ids never reach the wire.
        ->and($payload)->not->toHaveKey('subject_id')
        ->and($payload['properties'])->not->toHaveKey('workspace_id');
});
