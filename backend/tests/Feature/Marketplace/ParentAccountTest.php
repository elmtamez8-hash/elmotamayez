<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Support\PlatformWorkspace;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
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

/** @return array<string, mixed> */
function relationPayload(array $overrides = []): array
{
    return [
        'student_name' => 'سلمى',
        'relation_type' => RelationType::Parent->value,
        'permissions' => GuardianPermission::values(),
        ...$overrides,
    ];
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

// Since spec 003, absence of a preference row means "the type's defaults apply"
// (FR-028) — so a fresh account writes none, and every notification still reaches
// them. Seeding rows would freeze today's defaults for today's signups.
it('writes no preference rows at signup and still defaults to on', function (): void {
    $parent = registerParent();

    expect(NotificationPreference::query()
        ->where('user_id', $parent->getKey())->count())->toBe(0)
        ->and(NotificationType::AttendanceAlert->defaultChannels())
        ->toContain(NotificationChannel::InApp);
});

it('refuses a signup without the terms checkbox', function (): void {
    $this->postJson('/api/v1/auth/register/parent', parentPayload(['terms_accepted' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_accepted');
})->with([false, null, '0']);

it('adds a child who has no account yet', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);

    $this->postJson('/api/v1/family/relations', relationPayload([
        'age' => 14,
        'grade_level_slug' => 'secondary',
    ]))->assertStatus(201)
        ->assertJsonPath('student_name', 'سلمى')
        ->assertJsonPath('student_has_account', false)
        // Active at once: there is no account that could accept the link.
        ->assertJsonPath('status', 'active');

    expect(ParentStudentRelation::query()->where('guardian_user_id', $parent->getKey())->count())->toBe(1);
});

it('links an existing student account by uuid', function (): void {
    $parent = registerParent();
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($parent);

    $this->postJson('/api/v1/family/relations', relationPayload([
        'student_name' => $child->first_name,
        'student_uuid' => $child->uuid,
    ]))->assertStatus(201)
        ->assertJsonPath('student_has_account', true)
        // Pending: the student has an account, so the student gets a say.
        ->assertJsonPath('status', 'pending');
});

it('refuses to link the same student twice', function (): void {
    $parent = registerParent();
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs($parent);

    $payload = relationPayload(['student_uuid' => $child->uuid]);

    $this->postJson('/api/v1/family/relations', $payload)->assertStatus(201);
    $this->postJson('/api/v1/family/relations', $payload)->assertStatus(422);
});

// FR-019: one parent, many guardians. The second parent is refused; a guardian
// with the same details is not.
it('allows one parent and several guardians for the same student', function (): void {
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $first = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($first);
    $this->postJson('/api/v1/family/relations', relationPayload(['student_uuid' => $child->uuid]))
        ->assertStatus(201);

    $second = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($second);
    $this->postJson('/api/v1/family/relations', relationPayload(['student_uuid' => $child->uuid]))
        ->assertStatus(422);

    $this->postJson('/api/v1/family/relations', relationPayload([
        'student_uuid' => $child->uuid,
        'relation_type' => RelationType::Guardian->value,
    ]))->assertStatus(201);
});

// Answering "not a student" differently from "no such user" would turn this
// endpoint into a way to test whether an account exists.
it('answers identically for a missing account and a non-student account', function (): void {
    $parent = registerParent();
    $teacher = User::factory()->create(['platform_role' => PlatformRole::Teacher]);

    Sanctum::actingAs($parent);

    $missing = $this->postJson('/api/v1/family/relations', relationPayload([
        'student_uuid' => (string) Str::uuid(),
    ]));
    $wrongRole = $this->postJson('/api/v1/family/relations', relationPayload([
        'student_uuid' => $teacher->uuid,
    ]));

    expect($missing->status())->toBe(422)
        ->and($wrongRole->status())->toBe(422)
        ->and($missing->json('message'))->toBe($wrongRole->json('message'));
});

it('forbids a guardian from reading another family relation', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);
    $this->postJson('/api/v1/family/relations', relationPayload())->assertStatus(201);

    $relation = ParentStudentRelation::query()->firstOrFail();

    $stranger = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);

    // And their own list stays empty rather than showing someone else's child.
    // JsonResource::withoutWrapping() is on, so a collection is a bare array.
    expect($this->getJson('/api/v1/family/relations')->json())->toBe([]);
});

it('revokes a relation without deleting its history', function (): void {
    $parent = registerParent();
    Sanctum::actingAs($parent);
    $this->postJson('/api/v1/family/relations', relationPayload())->assertStatus(201);

    $relation = ParentStudentRelation::query()->firstOrFail();

    $this->deleteJson("/api/v1/family/relations/{$relation->uuid}")
        ->assertOk()
        ->assertJsonPath('status', 'revoked');

    expect(ParentStudentRelation::query()->count())->toBe(1)
        ->and($relation->fresh()->revoked_at)->not->toBeNull();
});
