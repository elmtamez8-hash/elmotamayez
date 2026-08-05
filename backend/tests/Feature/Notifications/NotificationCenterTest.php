<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('returns the feed newest first with an unread count', function (): void {
    $user = User::factory()->create();

    Notification::factory()->count(3)->create(['recipient_user_id' => $user->getKey()]);
    Notification::factory()->read()->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/notifications')->assertOk();

    expect($response->json('data'))->toHaveCount(4)
        ->and($response->json('meta.unread_count'))->toBe(3);

    $ids = array_column($response->json('data'), 'uuid');
    expect($ids[0])->toBe(Notification::query()->latest('id')->first()->uuid);
});

it('filters to unread only', function (): void {
    $user = User::factory()->create();
    Notification::factory()->count(2)->create(['recipient_user_id' => $user->getKey()]);
    Notification::factory()->read()->count(3)->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/notifications?unread=1')->json('data'))->toHaveCount(2);
});

it('serves the unread count on its own endpoint', function (): void {
    $user = User::factory()->create();
    Notification::factory()->count(5)->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJson(['unread_count' => 5]);
});

// FR-014: a double-click must not rewrite when the user first saw it.
it('marks one read, idempotently', function (): void {
    $user = User::factory()->create();
    $notification = Notification::factory()->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/notifications/{$notification->uuid}/read")
        ->assertOk()
        ->assertJson(['unread_count' => 0]);

    $firstReadAt = $notification->fresh()->read_at;

    $this->postJson("/api/v1/notifications/{$notification->uuid}/read")->assertOk();

    expect($notification->fresh()->read_at->toIso8601String())->toBe($firstReadAt->toIso8601String());
});

it('marks everything read in one statement', function (): void {
    $user = User::factory()->create();
    Notification::factory()->count(4)->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJson(['unread_count' => 0]);

    expect(Notification::query()->forRecipient($user)->unread()->count())->toBe(0);
});

// SC-009 — the leak that matters. There is no global scope on this table, so this
// is testing the guard itself, not a framework feature.
it('never shows one user another user notifications', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Notification::factory()->count(2)->create(['recipient_user_id' => $mine->getKey()]);
    $other = Notification::factory()->create(['recipient_user_id' => $theirs->getKey()]);

    Sanctum::actingAs($mine);

    expect($this->getJson('/api/v1/notifications')->json('data'))->toHaveCount(2);

    // 404 rather than 403: for a resource nobody may enumerate, confirming that a
    // uuid exists is itself the leak.
    $this->postJson("/api/v1/notifications/{$other->uuid}/read")->assertStatus(404);

    expect($other->fresh()->read_at)->toBeNull();
});

// FR-025ب — the reason notifications are NOT workspace-scoped. A parent following
// one child across four teachers has to see one stream.
it('shows the whole feed across workspaces and narrows only when asked', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أ']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);

    $user = User::factory()->create();

    Notification::factory()->create([
        'recipient_user_id' => $user->getKey(),
        'workspace_id' => $workspaceA->getKey(),
    ]);
    Notification::factory()->create([
        'recipient_user_id' => $user->getKey(),
        'workspace_id' => $workspaceB->getKey(),
    ]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/notifications')->json('data'))->toHaveCount(2)
        ->and($this->getJson("/api/v1/notifications?workspace={$workspaceA->uuid}")->json('data'))->toHaveCount(1);
});

it('filters by type', function (): void {
    $user = User::factory()->create();

    Notification::factory()->ofType(NotificationType::EnrollmentCreated)->create(['recipient_user_id' => $user->getKey()]);
    Notification::factory()->ofType(NotificationType::CertificateIssued)->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/notifications?type=certificate_issued')->json('data'))->toHaveCount(1);
});

it('caps the page size so a client cannot ask for everything', function (): void {
    $user = User::factory()->create();
    Notification::factory()->count(60)->create(['recipient_user_id' => $user->getKey()]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/notifications?per_page=500')->json('data'))->toHaveCount(50);
});

// SC-008. The claim is that the composite index (recipient_user_id, read_at, id)
// serves both the count and the first page, so neither scans the archive.
it('serves the first page and the count within budget at 10,000 notifications', function (): void {
    $user = User::factory()->create();

    $rows = [];
    $now = now();

    for ($i = 0; $i < 10_000; $i++) {
        $rows[] = [
            'uuid' => (string) Str::orderedUuid(),
            'recipient_user_id' => $user->getKey(),
            'type' => NotificationType::EnrollmentCreated->value,
            'payload' => '{}',
            'title_ar' => 'عنوان',
            'body_ar' => 'نصّ',
            'read_at' => $i % 2 === 0 ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }

    Sanctum::actingAs($user);

    $start = microtime(true);
    $this->getJson('/api/v1/notifications')->assertOk();
    $feedMs = (microtime(true) - $start) * 1000;

    $start = microtime(true);
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJson(['unread_count' => 5000]);
    $countMs = (microtime(true) - $start) * 1000;

    expect($feedMs)->toBeLessThan(300.0)
        ->and($countMs)->toBeLessThan(300.0);
});

it('requires authentication', function (): void {
    $this->asGuest();

    $this->getJson('/api/v1/notifications')->assertStatus(401);
    $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
});
