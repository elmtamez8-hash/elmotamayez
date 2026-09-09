<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Actions\LinkGuardian;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Http\Resources\PurchasableCourseResource;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\CourseParticipation;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|------------------------------------------------------------------------------
| Spec 031 · US3 — THE PICKER IS DERIVED FROM THE AUTHORISER, NOT ASSEMBLED
| BESIDE IT.
|------------------------------------------------------------------------------
|
| ⚠️ **SC-003 HAS TWO DIRECTIONS AND ONLY ONE OF THEM IS OBVIOUS.** «Nothing
| offered is refused» is the direction everybody writes; «nothing accepted is
| hidden» is the one that cost `ListLeaderboardScopes` a rewrite, because a picker
| that is merely NARROWER than the door looks perfect from the outside — every
| option works — while a paying customer is quietly never shown the thing they
| came to buy.
|
| So every case here walks options through the REAL `isPartyTo()`, in both
| directions, rather than comparing one list against another list.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->elsewhere, $this->otherTeacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية الفيزياء']);

    $this->enrolled = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->enrolled->forceFill(['title' => 'الفيزياء ٣'])->save();

    // Same teacher, no enrolment — reachable only through `isPartyTo`'s SECOND
    // arm, and therefore invisible to any picker built from `/enrollments`.
    $this->sibling = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->sibling->forceFill(['title' => 'الكيمياء ١'])->save();

    $this->draft = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->draft->forceFill(['status' => 'draft', 'title' => 'مسودّة'])->save();

    $this->foreign = courseWithRate((int) $this->elsewhere->getKey(), 5000);
    $this->foreign->forceFill(['title' => 'كورس غريب'])->save();

    $this->child = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Student,
        'first_name' => 'كريم',
    ]);

    $this->parent = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Parent,
    ]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->enrolled->getKey(),
        'student_user_id' => $this->child->getKey(),
        'status' => EnrollmentStatus::Active->value,
        'enrolled_at' => now(),
    ]);

    app()->forgetInstance(WorkspaceContext::class);
});

function linkAndAccept(User $guardian, User $student): void
{
    $relation = app(LinkGuardian::class)->handle($guardian, LinkGuardianData::fromArray([
        'student_name' => $student->name,
        'student_uuid' => $student->uuid,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => [GuardianPermission::Payments->value],
    ]));

    test()->actingAs($student, 'sanctum')
        ->postJson("/api/v1/family/relations/{$relation->uuid}/accept")
        ->assertOk();
}

/*
| SC-003, both ways at once — and the assertion is against `isPartyTo()` itself
| rather than against a second list this test builds. Comparing two lists proves
| the two lists agree; walking the door proves the picker is the door.
*/
it('offers exactly what the door accepts, in both directions (SC-003)', function (): void {
    linkAndAccept($this->parent, $this->child);

    $offered = $this->actingAs($this->parent, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses?student_uuid='.$this->child->uuid)
        ->assertOk()
        ->json('data');

    $participation = app(CourseParticipation::class);
    $uuids = array_column($offered, 'uuid');

    // ⇒ Nothing offered is refused.
    foreach ($offered as $option) {
        $course = Course::query()->withoutWorkspaceScope()->where('uuid', $option['uuid'])->firstOrFail();

        expect($participation->isPartyTo($this->child, $course))->toBeTrue($option['title']);
    }

    /*
    | ⇐ And nothing accepted is hidden. `الكيمياء ١` is the whole reason this door
    | exists: the child has no enrolment on it, so an enrolment-built picker
    | leaves it out — while the server prices it and sells it.
    */
    expect($uuids)->toContain((string) $this->enrolled->uuid)
        ->and($uuids)->toContain((string) $this->sibling->uuid)
        ->and($uuids)->not->toContain((string) $this->foreign->uuid);
});

/*
| A DRAFT is the one thing the picker withholds that the participation predicate
| would admit, and the asymmetry is deliberate: it has never been released, so
| offering it shows a buyer something that does not exist. `StopSellingGuard`
| refuses it at the door too, so the two still agree about the OUTCOME.
*/
it('withholds a course the teacher has never published, and the door refuses it too', function (): void {
    linkAndAccept($this->parent, $this->child);

    $offered = $this->actingAs($this->parent, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses?student_uuid='.$this->child->uuid)
        ->assertOk()
        ->json('data');

    expect(array_column($offered, 'uuid'))->not->toContain((string) $this->draft->uuid);

    // And it is not merely hidden: buying it is refused with a sentence.
    $this->actingAs($this->parent, 'sanctum')->postJson('/api/v1/billing/purchases', [
        'course' => (string) $this->draft->uuid,
        'package' => (string) CreditPackage::query()->create([
            'name' => 'حصّتان',
            'credits' => 2,
            'session_type' => ClassSessionType::Individual,
            'is_active' => true,
            'sort_order' => 1,
        ])->uuid,
        'student_uuid' => (string) $this->child->uuid,
    ])->assertStatus(422);
});

/*
| ⛔ THE PAYER IS PART OF THE QUESTION. A teacher who is also a parent would
| otherwise be OFFERED their own course through their child and then refused when
| they pressed it — two answers to one question from two spellings, which is
| exactly the defect `BookingEligibility`'s host check and `ListLeaderboardScopes`
| were each written to close.
*/
it('withholds the payer own courses from the payer, not merely from the child', function (): void {
    $this->teacher->forceFill(['platform_role' => PlatformRole::Parent])->save();

    linkAndAccept($this->teacher, $this->child);

    $offered = $this->actingAs($this->teacher, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses?student_uuid='.$this->child->uuid)
        ->assertOk()
        ->json('data');

    // The child is party to both; the PAYER sells both, so neither is offered.
    expect($offered)->toBe([]);
});

/*
| A student asking for themselves needs no `student_uuid` at all — and a GUARDIAN
| who names nobody is refused rather than shown an empty list, because an empty
| list reads as «you have nothing to buy» when the truth is «you have not said
| for whom» (the same sentence the other two doors give).
*/
it('answers a student about themselves and refuses a guardian who names nobody', function (): void {
    $offered = $this->actingAs($this->child, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses')
        ->assertOk()
        ->json('data');

    expect(array_column($offered, 'uuid'))->toContain((string) $this->enrolled->uuid);

    $this->actingAs($this->parent, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses')
        ->assertStatus(422)
        ->assertJsonPath('message', 'اختر الطالب الذي تدفع له.');
});

/*
| ⚠️ THE ENVELOPE. `/enrollments` dropped it once and every reader showed zero —
| four screens, every student on the platform, no error anywhere, because the
| four clients all did `res.data ?? []` and an array has no `.data`. An index with
| no shape test is an index whose shape is whatever the first person typed.
*/
it('answers a paginated envelope, never a bare array', function (): void {
    $body = $this->actingAs($this->child, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses')
        ->assertOk()
        ->json();

    expect($body)->toHaveKeys(['data', 'links', 'meta']);
});

/*
|------------------------------------------------------------------------------
| T027 · THE ALLOWLIST — the build reds over a field added here.
|------------------------------------------------------------------------------
|
| Same shape as `StudentBalanceAllowlist`, and for the same reason: a resource
| with nothing watching it grows one field at a time, and the one that matters is
| never the one anybody notices. `CourseResource` — the obvious thing to reuse —
| carries `status`, `visibility`, `price_minor` and `promo_video_status`, on a
| payload whose reader is a parent picking a course to top up.
*/
it('sends four fields and no fifth', function (): void {
    linkAndAccept($this->parent, $this->child);

    $offered = $this->actingAs($this->parent, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses?student_uuid='.$this->child->uuid)
        ->assertOk()
        ->json('data');

    expect($offered)->not->toBe([]);

    foreach ($offered as $option) {
        expect(array_keys($option))->toBe(PurchasableCourseResource::FIELDS);
    }
});

/*
|------------------------------------------------------------------------------
| `GET /billing/beneficiaries`
|------------------------------------------------------------------------------
*/

it('lists only children with an ACCEPTED link and the payments permission', function (): void {
    $accepted = $this->child;
    $pendingOnly = User::factory()->create([
        'last_workspace_id' => null,
        'platform_role' => PlatformRole::Student,
        'first_name' => 'سارة',
    ]);

    linkAndAccept($this->parent, $accepted);

    // Requested and never answered — 030's whole point: `pending` names an
    // account and grants nothing until the student says yes.
    app(LinkGuardian::class)->handle($this->parent, LinkGuardianData::fromArray([
        'student_name' => $pendingOnly->name,
        'student_uuid' => $pendingOnly->uuid,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => [GuardianPermission::Payments->value],
    ]));

    $names = $this->actingAs($this->parent, 'sanctum')
        ->getJson('/api/v1/billing/beneficiaries')
        ->assertOk()
        ->json();

    expect($names)->toHaveCount(1)
        ->and($names[0]['uuid'])->toBe((string) $accepted->uuid)
        // Two fields and no more: «may pay for» is not «may read everything about».
        ->and(array_keys($names[0]))->toBe(['uuid', 'name']);
});

it('answers an empty list for somebody with no children, rather than an error', function (): void {
    expect($this->actingAs($this->child, 'sanctum')
        ->getJson('/api/v1/billing/beneficiaries')
        ->assertOk()
        ->json())->toBe([]);
});

it('is not reachable without signing in', function (): void {
    $this->getJson('/api/v1/billing/beneficiaries')->assertUnauthorized();
    $this->getJson('/api/v1/billing/purchasable-courses')->assertUnauthorized();
});

/*
| A workspace MEMBER with the STUDENT role is `isPartyTo`'s third arm — the first
| purchase, before any enrolment exists. If the picker missed it, the person it
| left out would be exactly the student who has not bought anything yet.
*/
it('offers a workspace member with the student role, who has no enrolment at all', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $uuids = array_column($this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses')
        ->assertOk()
        ->json('data'), 'uuid');

    expect($uuids)->toContain((string) $this->enrolled->uuid)
        ->and($uuids)->toContain((string) $this->sibling->uuid)
        ->and($uuids)->not->toContain((string) $this->foreign->uuid);
});

/*
| And the teaching side is subtracted rather than merely not added: an enrolment
| is a row a teacher can cause to exist, so a set that only failed to include
| them would be one `Enrollment::create` away from being no refusal at all.
*/
it('offers a teacher nothing on their own workspace, even holding an enrolment there', function (): void {
    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->enrolled->getKey(),
        'student_user_id' => $this->teacher->getKey(),
        'status' => EnrollmentStatus::Active->value,
        'enrolled_at' => now(),
    ]);

    $uuids = array_column($this->actingAs($this->teacher, 'sanctum')
        ->getJson('/api/v1/billing/purchasable-courses')
        ->assertOk()
        ->json('data'), 'uuid');

    expect($uuids)->not->toContain((string) $this->enrolled->uuid);
});
