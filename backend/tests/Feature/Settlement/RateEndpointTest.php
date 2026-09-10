<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| The routes, and who may reach them.
|
| The split that matters is between asking and deciding: a teacher who could
| approve their own rate would make the approval step theatre, and the step
| exists because approving one moves the sale price the whole marketplace shows.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'effective_from' => CarbonImmutable::now()->subYear(),
    ]);
});

it('lets the teacher file a request', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/v1/settlement/rate-requests', [
        'session_type' => ClassSessionType::Individual->value,
        'requested_amount_minor' => 9000,
    ])->assertStatus(201)->assertJsonPath('status', 'pending');

    // Filed, not granted: still one rate on file.
    expect(SettlementRate::query()->count())->toBe(1);
});

it('refuses a second pending request with the reason under the field', function (): void {
    Sanctum::actingAs($this->owner);

    $payload = [
        'session_type' => ClassSessionType::Individual->value,
        'requested_amount_minor' => 9000,
    ];

    $this->postJson('/api/v1/settlement/rate-requests', $payload)->assertStatus(201);

    // 422 with the message under its field, which is the shape fieldErrors()
    // reads — anything else lands as a banner with no context.
    $this->postJson('/api/v1/settlement/rate-requests', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['requested_amount_minor']]);
});

it('refuses the teacher their own approval', function (): void {
    $request = RateChangeRequest::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'requested_by' => $this->owner->getKey(),
    ]);

    // The workspace owner holds every teaching permission there is and still
    // fails here: approving is SETTLEMENT_RATE_APPROVE, which reaches only
    // platform admin — exactly like approving a marketplace application.
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/admin/settlement/rate-requests/{$request->uuid}/approve")
        ->assertForbidden();
});

it('lets a platform admin approve, and tells the teacher when it takes effect', function (): void {
    $request = RateChangeRequest::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'requested_by' => $this->owner->getKey(),
        'requested_amount_minor' => 9000,
    ]);

    // Super Admin is the platform-level `users.is_super_admin` flag, never a
    // tenant role — `Gate::before` passes on the flag alone. Adding them as a
    // workspace member too, because the request being decided lives in one.
    $admin = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->setCurrentWorkspace($this->workspace, $admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/settlement/rate-requests/{$request->uuid}/approve")
        ->assertStatus(201)
        ->assertJsonPath('amount_minor', 9000);

    $notification = Notification::query()
        ->where('type', NotificationType::SettlementRateApproved->value)
        ->first();

    // The date is the point: a new number with no "from when" reads as applying
    // to hours already taught.
    expect($notification)->not->toBeNull()
        ->and($notification->body)->toContain('يسري من');
});

it('refuses a student the settlement routes entirely', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/settlement/rate-requests')->assertForbidden();
    $this->getJson('/api/v1/settlement/units')->assertForbidden();
});

it('refuses an assistant teacher the settlement routes entirely', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    // FR-020 · SC-010 — the assistant runs the classroom and holds every session
    // permission for it. Reading the teacher's rate is reading their contract,
    // which is a different question with a different answer.
    Sanctum::actingAs($assistant);

    $this->getJson('/api/v1/settlement/rate-requests')->assertForbidden();
    $this->getJson('/api/v1/settlement/units')->assertForbidden();
});
