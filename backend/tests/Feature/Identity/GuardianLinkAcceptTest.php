<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\LinkGuardian;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Payments\Support\PurchaseBeneficiary;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| Spec 030 — a pending guardian link becomes decidable.
|
| ⚠️ NOT ONE FIXTURE IN THIS FILE WRITES `status => 'active'` BY HAND, and that is
| the whole point. The feature it replaces was «green over data the platform cannot
| produce»: every existing test built its relation with the status already set, so
| the suite proved the guardian features worked while `LinkGuardian` was writing
| `pending` and nothing in `app/` could move it. Each link here is created by the
| real Action and activated by the real route.
|
| ⚠️ AND THE STUDENT'S `last_workspace_id` IS NEVER STAMPED. A student is a member
| of no workspace, so `WorkspaceContext::id()` is null for them in production —
| `addWorkspaceMember()` and both seeders stamp that column, which is how five
| student-facing endpoints once shipped dead behind a green suite.
*/

function linkedStudent(): User
{
    return User::factory()->create(['platform_role' => PlatformRole::Student]);
}

function linkingGuardian(): User
{
    return User::factory()->create(['platform_role' => PlatformRole::Parent]);
}

/** The relation as the product actually makes one: through the Action, `pending`. */
function requestedLink(User $guardian, User $student, GuardianPermission ...$permissions): ParentStudentRelation
{
    return app(LinkGuardian::class)->handle($guardian, LinkGuardianData::fromArray([
        'student_name' => $student->name,
        'student_uuid' => $student->uuid,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => array_map(
            static fn (GuardianPermission $permission): string => $permission->value,
            $permissions === [] ? [GuardianPermission::Payments] : $permissions,
        ),
    ]));
}

it('is born pending and reaches no guardian read until it is accepted', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();

    $relation = requestedLink($guardian, $student);

    expect($relation->status)->toBe(RelationStatus::Pending->value)
        ->and($relation->requested_by_user_id)->toBe($guardian->getKey());

    // The defect this phase exists to remove: the guardian's own child list is
    // empty, so every guardian-gated feature answers nothing.
    expect(app(GuardianDirectory::class)->childrenOf($guardian, GuardianPermission::Payments))
        ->toHaveCount(0);
});

it('activates on the student accepting, and only then does the guardian read the child (SC-001 · SC-002)', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();

    $relation = requestedLink($guardian, $student);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk()
        ->assertJsonPath('status', RelationStatus::Active->value)
        ->assertJsonPath('viewer_side', 'student')
        ->assertJsonPath('can_decide', false)
        // FR-007: the guardian's name has to survive the accept response, which is
        // the one response a screen renders in place rather than re-listing.
        ->assertJsonPath('guardian.name', $guardian->name);

    expect(ParentStudentRelation::query()->find($relation->getKey())->accepted_at)->not->toBeNull();

    $children = app(GuardianDirectory::class)->childrenOf($guardian, GuardianPermission::Payments);

    expect($children)->toHaveCount(1)
        ->and($children->first()->getKey())->toBe($student->getKey());

    // SC-002 — this is what unlocks the purchase shipped in 029. Asked of the real
    // resolver, on a database that never wrote the status by hand.
    $resolved = app(PurchaseBeneficiary::class)->resolve($guardian, $student->uuid);

    expect($resolved['student']->getKey())->toBe($student->getKey())
        ->and($resolved['grantedBy']?->getKey())->toBe($guardian->getKey());
});

it('refuses every actor who is not the party that was asked (SC-003)', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();
    $relation = requestedLink($guardian, $student);

    // The requester cannot answer on behalf of the person they asked.
    Sanctum::actingAs($guardian);
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertForbidden();

    // A stranger.
    Sanctum::actingAs(linkedStudent());
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertForbidden();

    /*
    | ⚠️ AND THE SUPER ADMIN, WHO IS THE WHOLE REASON THE ACTION RE-ASKS.
    | `AppServiceProvider`'s `Gate::before` waves them past every policy method, so
    | without this actor the file measures a branch that was never in doubt — and
    | the refusal arrives as 422 rather than 403, because a `DomainException` on an
    | API route is mapped there.
    */
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكنك البتّ في هذا الطلب.');

    expect(ParentStudentRelation::query()->find($relation->getKey())->status)
        ->toBe(RelationStatus::Pending->value);
});

it('does not re-stamp the acceptance date on a second attempt (SC-006)', function (): void {
    $student = linkedStudent();
    $relation = requestedLink(linkingGuardian(), $student);

    Sanctum::actingAs($student);

    /*
    | ⚠️ THE KEY IS TOP-LEVEL, NOT UNDER `data`. This app calls
    | `JsonResource::withoutWrapping()`, so `json('data.accepted_at')` returns null
    | — on BOTH calls — and the comparison passes by finding nothing twice. The
    | non-null assertion below is what makes the equality mean anything.
    */
    $first = $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk()->json('accepted_at');

    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();

    $second = $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk()->json('accepted_at');

    expect($second)->toBe($first);
});

it('says the request has ended rather than reporting a success on a cut link', function (): void {
    $student = linkedStudent();
    $relation = requestedLink(linkingGuardian(), $student);

    Sanctum::actingAs($student);

    // Rejecting IS the existing delete route — `RevokeRelation` neither deletes nor
    // re-stamps, and its policy is `isParty`, so no second Action was invented.
    $this->deleteJson("/api/v1/family/relations/{$relation->uuid}")->assertOk();

    /*
    | ⚠️ THE BRANCH THAT ALMOST SHIPPED BACKWARDS. The conditional UPDATE matches
    | zero rows here — exactly as it does for an already-accepted link — so a branch
    | on the row count alone would have told the student who just refused that the
    | acceptance succeeded.
    */
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertStatus(422)
        ->assertJsonPath('message', 'انتهى هذا الطلب.');

    expect(ParentStudentRelation::query()->find($relation->getKey())->status)
        ->toBe(RelationStatus::Revoked->value);
});

it('refuses an old pending row whose requester nobody can prove', function (): void {
    $student = linkedStudent();
    $relation = requestedLink(linkingGuardian(), $student);

    // What a row created before this column existed looks like. FR-011: it is not
    // backfilled, and assuming a requester would invent a consent nobody gave.
    $relation->forceFill(['requested_by_user_id' => null])->save();

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertForbidden();
});

it('settles the other direction, where the student asked and the guardian answers', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();

    // The shape `RegisterStudent::inviteGuardian` writes: the STUDENT asked.
    $relation = ParentStudentRelation::factory()->pending()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'requested_by_user_id' => $student->getKey(),
        'permissions' => [GuardianPermission::DataRights->value],
    ]);

    // The requester — here the student — may not answer their own request.
    Sanctum::actingAs($student);
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertForbidden();

    Sanctum::actingAs($guardian);
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk()
        ->assertJsonPath('viewer_side', 'guardian');

    expect(app(GuardianDirectory::class)->isAuthorised($guardian, $student, GuardianPermission::DataRights))
        ->toBeTrue()
        // Acceptance is the guardian's own act, not a wider permission set.
        ->and(app(GuardianDirectory::class)->isAuthorised($guardian, $student, GuardianPermission::Payments))
        ->toBeFalse();
});

it('lets a refused link be requested again as a NEW row (FR-012)', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();

    $first = requestedLink($guardian, $student);

    Sanctum::actingAs($student);
    $this->deleteJson("/api/v1/family/relations/{$first->uuid}")->assertOk();

    /*
    | The old `unique(guardian_user_id, student_user_id)` made this impossible, so
    | a refusal was permanent and FR-012's rate limit guarded something that could
    | never happen. The index did not go away — it grew a sentinel column, because
    | replacing it with the Action's `exists()` check would have left two active
    | rows possible, and then `RevokeRelation` cuts one while the other keeps
    | granting access.
    */
    $second = requestedLink($guardian, $student);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->status)->toBe(RelationStatus::Pending->value);

    // And the refusal itself is still readable — FR-004, revoked and not deleted.
    expect(ParentStudentRelation::query()->find($first->getKey())->status)
        ->toBe(RelationStatus::Revoked->value);
});

it('still refuses a second live link for one pair', function (): void {
    $student = linkedStudent();
    $guardian = linkingGuardian();

    requestedLink($guardian, $student);

    expect(fn () => requestedLink($guardian, $student))
        ->toThrow(DomainException::class, 'هذا الطالب مرتبط بحسابك بالفعل.');
});
