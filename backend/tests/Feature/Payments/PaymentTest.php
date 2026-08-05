<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Providers\ManualTransferProvider;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function createPaidCourse(int $workspaceId): Course
{
    return Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'price' => 49.99,
        'is_sequential' => false,
    ]);
}

describe('order creation', function (): void {
    it('creates an order for a paid published course', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/orders")
            ->assertCreated()
            ->assertJsonPath('amount', 49.99)
            ->assertJsonPath('status', 'pending');

        expect(Order::where('course_id', $course->id)->count())->toBe(1);
    });

    it('prevents ordering a free course', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->published()->free()->create(['workspace_id' => $workspace->id]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/orders")
            ->assertStatus(422);
    });
});

describe('receipt upload', function (): void {
    it('allows a student to upload a receipt', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $order = app(CreateOrder::class)->handle($course, $student);

        $this->postJson("/api/v1/orders/{$order->uuid}/receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 test'),
        ])->assertOk()
            ->assertJsonPath('status', 'under_review')
            ->assertJsonPath('has_receipt', true)
            ->assertJsonPath('is_mine', true)
            ->assertJsonPath('receipt_url', fn (?string $url) => $url !== null && str_contains($url, 'receipt'));

        expect($order->fresh()->getFirstMedia('receipt'))->not->toBeNull();
    });

    it('prevents uploading a receipt for someone else\'s order', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student1 = $this->addWorkspaceMember($workspace, 'student');
        $student2 = User::factory()->create();
        $this->addWorkspaceMember($workspace, 'student', $student2);

        $order = app(CreateOrder::class)->handle($course, $student1);

        Sanctum::actingAs($student2);

        $this->postJson("/api/v1/orders/{$order->uuid}/receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 test'),
        ])->assertForbidden();
    });
});

describe('payment approval critical path', function (): void {
    it('approves an order and auto-creates an enrollment', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        // Student creates order.
        $orderResp = $this->postJson("/api/v1/courses/{$course->uuid}/orders")->assertCreated();
        $orderUuid = $orderResp->json('uuid');

        // Student uploads receipt.
        $this->postJson("/api/v1/orders/{$orderUuid}/receipt", [
            'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 test'),
        ])->assertOk();

        // Approver (teacher/owner) approves.
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/orders/{$orderUuid}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        // Enrollment should exist.
        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('student_user_id', $student->id)
            ->first();

        expect($enrollment)->not->toBeNull()
            ->and($enrollment->source)->toBe('purchase')
            ->and($enrollment->order_id)->not->toBeNull();

        // Student should have been notified about the enrollment. One record, on
        // our own table — Laravel's notifications schema was replaced in spec 003.
        $notification = Notification::query()
            ->where('recipient_user_id', $student->id)
            ->where('type', NotificationType::EnrollmentCreated->value)
            ->first();

        expect($notification)->not->toBeNull()
            ->and($notification->payload['course_title'])->toBe($course->title);
    });

    it('rejects an order with a reason', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $orderResp = $this->postJson("/api/v1/courses/{$course->uuid}/orders");
        $orderUuid = $orderResp->json('uuid');

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/orders/{$orderUuid}/reject", [
            'reason' => 'Receipt is illegible.',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $order = Order::where('uuid', $orderUuid)->first();
        expect($order->status)->toBe('rejected')
            ->and($order->rejection_reason)->toBe('Receipt is illegible.')
            ->and(Enrollment::where('course_id', $course->id)->exists())->toBeFalse();
    });

    it('denies a student from approving payments', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $order = app(CreateOrder::class)->handle($course, $student);

        $this->postJson("/api/v1/orders/{$order->uuid}/approve")
            ->assertForbidden();
    });

    it('prevents cross-workspace order access', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspaceA->id);

        Sanctum::actingAs($ownerB);

        $this->postJson("/api/v1/courses/{$course->uuid}/orders")
            ->assertNotFound();
    });
});

describe('provider abstraction (OCP)', function (): void {
    it('resolves the manual provider from the interface', function (): void {
        $provider = app(PaymentProviderInterface::class);

        expect($provider)->toBeInstanceOf(ManualTransferProvider::class)
            ->and($provider->identifier())->toBe('manual')
            ->and($provider->supportsRefund())->toBeFalse();
    });

    it('manual provider createCharge returns bank-transfer instructions', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $student = $this->addWorkspaceMember($workspace, 'student');

        $order = app(CreateOrder::class)->handle($course, $student);

        $charge = app(PaymentProviderInterface::class)->createCharge($order);

        expect($charge['method'])->toBe('bank_transfer')
            ->and($charge['amount'])->toBe(49.99);
    });
});

describe('order details', function (): void {
    it('shows a single order to its owner', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $student = $this->addWorkspaceMember($workspace, 'student');

        $order = app(CreateOrder::class)->handle($course, $student);

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $order->uuid)
            ->assertJsonPath('amount', 49.99);
    });

    it('allows staff with view-all to see any order', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $student = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');

        $order = app(CreateOrder::class)->handle($course, $student);

        Sanctum::actingAs($teacher);

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk();
    });

    it('prevents a student from viewing another student\'s order', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $studentA = $this->addWorkspaceMember($workspace, 'student');
        $studentB = $this->addWorkspaceMember($workspace, 'student');

        $order = app(CreateOrder::class)->handle($course, $studentA);

        Sanctum::actingAs($studentB);

        $this->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
    });
});

describe('order listing', function (): void {
    it('lists own orders for a student', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $student = $this->addWorkspaceMember($workspace, 'student');

        app(CreateOrder::class)->handle($course, $student);

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1);
    });

    it('lists all orders for staff with view-all', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createPaidCourse($workspace->id);
        $studentA = $this->addWorkspaceMember($workspace, 'student');
        $studentB = $this->addWorkspaceMember($workspace, 'student');
        $teacher = $this->addWorkspaceMember($workspace, 'teacher');

        app(CreateOrder::class)->handle($course, $studentA);
        app(CreateOrder::class)->handle($course, $studentB);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2);
    });
});

/**
 * A receipt is a bank transfer document tied to a named person. It lives on the
 * private disk, and the only way to read it is a signed link minted for someone
 * the `view` policy already allowed.
 */
it('serves a receipt through a signed link and refuses an unsigned one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    $order = app(CreateOrder::class)->handle($course, $owner);

    app(UploadPaymentReceipt::class)->handle(
        $order,
        UploadedFile::fake()->image('receipt.png'),
        $owner,
    );

    Sanctum::actingAs($owner);

    $url = $this->getJson("/api/v1/orders/{$order->uuid}")->json('receipt_url');

    expect($url)->toContain('signature=')
        // The old path served the public disk; the file is on the private one.
        ->and($url)->not->toContain('/storage/');

    $path = str_replace(config('app.url'), '', (string) $url);

    $this->get($path)->assertOk();
    // Strip the signature and the door closes.
    $this->get(explode('?', $path)[0])->assertStatus(403);
});
