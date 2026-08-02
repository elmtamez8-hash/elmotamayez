<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\NotificationPreference;
use App\Modules\Identity\Models\ParentChildLink;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Support\PlatformWorkspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Parent accounts: signing up, linking a child, and the wall between one family
 * and the next.
 */
beforeEach(function (): void {
    $workspace = PlatformWorkspace::resolve();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
        GradeLevel::factory()->create([
            'slug' => 'secondary',
            'workspace_id' => $workspace->getKey(),
            'is_active' => true,
        ]);
    });

    $this->asGuest();
});

/** @return array<string, mixed> */
function parentPayload(array $overrides = []): array
{
    return [
        'first_name' => 'منى',
        'last_name' => 'الكواري',
        'email' => 'mona@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455511122',
        'country' => 'QA',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

function registerParent(): User
{
    test()->postJson('/api/v1/auth/register/parent', parentPayload())->assertStatus(201);

    return User::query()->where('email', 'mona@example.com')->firstOrFail();
}

it('creates a parent with no workspace and no tenant role', function (): void {
    $response = $this->postJson('/api/v1/auth/register/parent', parentPayload());

    $response->assertStatus(201)
        ->assertJsonPath('user.platform_role', 'parent')
        ->assertJsonStructure(['token']);

    $parent = User::query()->where('email', 'mona@example.com')->firstOrFail();

    expect($parent->last_workspace_id)->toBeNull()
        ->and($parent->workspaces()->count())->toBe(0)
        ->and($parent->getRoleNames()->all())->toBe([]);
});

it('turns both notification preferences on at signup', function (): void {
    $parent = registerParent();

    $preferences = NotificationPreference::query()->where('user_id', $parent->getKey())->firstOrFail();

    expect($preferences->weekly_reports)->toBeTrue()
        ->and($preferences->session_alerts)->toBeTrue();
});

it('refuses a signup without the terms checkbox', function (): void {
    $this->postJson('/api/v1/auth/register/parent', parentPayload(['terms_accepted' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_accepted');
})->with([false, null, '0']);

it('adds a child who has no account yet', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);

    $this->postJson('/api/v1/parent/children', [
        'name' => 'سلمى',
        'age' => 14,
        'grade_level_slug' => 'secondary',
    ])->assertStatus(201)
        ->assertJsonPath('name', 'سلمى')
        ->assertJsonPath('has_account', false);

    expect(ParentChildLink::query()->where('parent_id', $parent->getKey())->count())->toBe(1);
});

it('links an existing student account by uuid', function (): void {
    $parent = registerParent();
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($parent);

    $this->postJson('/api/v1/parent/children', [
        'name' => $child->first_name,
        'child_uuid' => $child->uuid,
    ])->assertStatus(201)->assertJsonPath('has_account', true);
});

it('refuses to link the same student twice', function (): void {
    $parent = registerParent();
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($parent);

    $payload = ['name' => 'سلمى', 'child_uuid' => $child->uuid];

    $this->postJson('/api/v1/parent/children', $payload)->assertStatus(201);
    $this->postJson('/api/v1/parent/children', $payload)->assertStatus(422);
});

// Answering "not a student" differently from "no such user" would turn this
// endpoint into a way to test whether an account exists.
it('answers identically for a missing account and a non-student account', function (): void {
    $parent = registerParent();
    $teacher = User::factory()->create(['platform_role' => PlatformRole::Teacher]);

    Sanctum::actingAs($parent);

    $missing = $this->postJson('/api/v1/parent/children', [
        'name' => 'س',
        'child_uuid' => (string) Str::uuid(),
    ]);
    $wrongRole = $this->postJson('/api/v1/parent/children', [
        'name' => 'س',
        'child_uuid' => $teacher->uuid,
    ]);

    expect($missing->status())->toBe(422)
        ->and($wrongRole->status())->toBe(422)
        ->and($missing->json('message'))->toBe($wrongRole->json('message'));
});

it('forbids a parent from reading another family child (FR-075)', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);
    $this->postJson('/api/v1/parent/children', ['name' => 'سلمى'])->assertStatus(201);

    $link = ParentChildLink::query()->firstOrFail();

    $stranger = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/parent/children/{$link->uuid}")->assertStatus(403);

    // And their own list stays empty rather than showing someone else's child.
    expect($this->getJson('/api/v1/parent/children')->json('data'))->toBe([]);
});

it('reads and updates the notification preferences', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);

    $this->getJson('/api/v1/parent/notification-preferences')
        ->assertOk()
        ->assertJson(['weekly_reports' => true, 'session_alerts' => true]);

    $this->putJson('/api/v1/parent/notification-preferences', [
        'weekly_reports' => false,
        'session_alerts' => true,
    ])->assertOk()->assertJson(['weekly_reports' => false]);

    expect(NotificationPreference::query()->where('user_id', $parent->getKey())->firstOrFail()->weekly_reports)
        ->toBeFalse();
});
