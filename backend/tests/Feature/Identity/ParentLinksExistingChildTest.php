<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| THE WHOLE JOURNEY: a parent links a child who ALREADY HAS AN ACCOUNT.
|
| The server side of this existed since spec 030 — `LinkGuardian` takes a
| `student_uuid`, writes the row `pending`, and only the child can accept it. What
| was missing was every screen: `/family` and the signup form never sent the
| field, so every child a parent added became a name-only row, `active` at once,
| with no account behind it — and `ChildSwitcher` (rightly) never offered it.
|
| This file walks the path end to end over HTTP, in both failure directions: the
| parent cannot activate their own request, and an identifier that names nobody
| answers exactly as one that names a non-student.
*/

function existingChildPayload(string $uuid): array
{
    return [
        'student_name' => 'كريم',
        'student_uuid' => $uuid,
        'relation_type' => 'parent',
        'permissions' => [GuardianPermission::Attendance->value, GuardianPermission::Results->value],
    ];
}

it('links an existing child only once the child accepts', function (): void {
    $child = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $parent = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    Sanctum::actingAs($parent);

    $uuid = $this->postJson('/api/v1/family/relations', existingChildPayload($child->uuid))
        ->assertCreated()
        ->assertJsonPath('status', RelationStatus::Pending->value)
        ->json('uuid');

    // Pending grants nothing — no report, attendance or balance reaches the parent.
    expect(app(GuardianDirectory::class)->childrenOf($parent, GuardianPermission::Attendance))->toBeEmpty();

    // The parent cannot settle their own request.
    $this->postJson("/api/v1/family/relations/{$uuid}/accept")->assertForbidden();
    expect(ParentStudentRelation::query()->sole()->status)->toBe(RelationStatus::Pending->value);

    // The child sees it, can decide it, and accepts.
    Sanctum::actingAs($child);

    $this->getJson('/api/v1/family/relations')
        ->assertOk()
        ->assertJsonPath('0.viewer_side', 'student')
        ->assertJsonPath('0.can_decide', true);

    $this->postJson("/api/v1/family/relations/{$uuid}/accept")
        ->assertOk()
        ->assertJsonPath('status', RelationStatus::Active->value);

    // Now the parent's list carries what ChildSwitcher needs: active + the uuid.
    Sanctum::actingAs($parent);

    $this->getJson('/api/v1/family/relations')
        ->assertOk()
        ->assertJsonPath('0.status', RelationStatus::Active->value)
        ->assertJsonPath('0.student_uuid', $child->uuid);

    expect(app(GuardianDirectory::class)->childrenOf($parent, GuardianPermission::Attendance)
        ->pluck('id')->all())->toBe([$child->getKey()]);
});

it('answers an unknown identifier exactly as a non-student one, writing nothing', function (): void {
    $teacher = User::factory()->create(['platform_role' => PlatformRole::Teacher]);

    Sanctum::actingAs(User::factory()->create(['platform_role' => PlatformRole::Parent]));

    $unknown = $this->postJson('/api/v1/family/relations', existingChildPayload((string) Str::uuid()))
        ->assertStatus(422);
    $notStudent = $this->postJson('/api/v1/family/relations', existingChildPayload($teacher->uuid))
        ->assertStatus(422);

    expect($unknown->json())->toBe($notStudent->json())
        ->and($unknown->json('message'))->toBe('لم نجد حساب طالب بهذا المعرّف.')
        ->and(ParentStudentRelation::query()->count())->toBe(0);
});
