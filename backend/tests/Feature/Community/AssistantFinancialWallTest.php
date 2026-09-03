<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-001 · FR-003 — an assistant reaches no financial surface, over HTTP, with a
| real account.
|
| ⚠️ THE FIXTURE NEUTRALISES EVERY OTHER REASON A REQUEST COULD BE REFUSED. The
| assistant is a member of the right workspace, holds the permission each route
| asks for, and is confined to the course the fixture uses — so a 403 here can
| only be the wall. Without that, every assertion below would pass just as
| happily against a product with no wall in it at all, because an assistant holds
| none of these permissions by default. `T054` re-measures exactly that, by
| deleting the hook and requiring this file to go red.
|
| ⚠️ AND THE PERMISSIONS ARRIVE ON A ROLE WITH A DIFFERENT NAME. Measuring
| against `assistant-teacher`'s own seeded set proves `RolePermissionMatrix`, not
| the wall — and the matrix is a SEED that runs once at workspace creation, so an
| owner who invents «مصحّح» and ticks `payments.approve` onto it from the roles
| screen is the case the wall exists for. `Tenancy\Models\Role` refuses PLATFORM
| permissions on a workspace role, which is why the list below holds the six
| financial names a tenant role may legally carry and not one more.
|
| ⚠️ THREE GROUPS, DELIBERATELY SEPARATE, AND ONLY THE FIRST MEASURES THE WALL.
| Everything under «الحائط» goes red when the hook is deleted. «ت-١» stays green
| by design — the ORDINARY absence of `billing.balance.view` and the exception
| that lets an owner grant it back on purpose. And the third group holds what
| USED to sit in the first: `payments.approve` and `payments.reject` left for the
| platform with the transfer decision, so refusing them is no longer the hook's
| doing and a case measuring it must not be counted as the wall standing. Mixed
| together, `T054`'s «أعِده» could not tell the three apart and would prove
| nothing.
*/

/**
 * The financial permissions a workspace role may legally hold.
 *
 * ⚠️ SIX, NOT EIGHT — AND THE TWO THAT LEFT KILLED THIS FIXTURE IN ITS
 * `beforeEach`. `payments.approve` and `payments.reject` moved to the platform
 * when the transfer decision did, and `Tenancy\Models\Role` THROWS when a
 * platform permission is attached to a role carrying a `team_id`. So the list
 * that exists to prove the wall could no longer be built, and all eight cases
 * below died at once — not on an assertion, on the fixture. Removing a name
 * from a tenant role is the same deploy-order trap as adding one, run
 * backwards, and only CI saw it.
 */
function walledTenantPermissions(): array
{
    return [
        Permissions::ORDERS_VIEW_ALL,
        Permissions::ORDERS_VIEW_OWN,
        Permissions::ORDERS_CREATE,
        Permissions::SETTLEMENT_RATE_REQUEST,
        Permissions::SETTLEMENT_STATEMENT_VIEW,
        Permissions::BILLING_EXAM_MODE_MANAGE,
    ];
}

/**
 * The two the platform took, which no role here can be given.
 *
 * They still belong in the ABSENCE assertions — a name an assistant cannot be
 * granted must also be a name the sidebar never offers them — but they can no
 * longer appear in anything that reaches `syncPermissions()`.
 *
 * @return list<string>
 */
function walledPlatformPermissions(): array
{
    return [Permissions::PAYMENTS_APPROVE, Permissions::PAYMENTS_REJECT];
}

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $invented = Role::query()->create([
        'name' => 'مصحّح',
        'guard_name' => 'web',
        'team_id' => $this->workspace->getKey(),
    ]);
    $invented->syncPermissions(walledTenantPermissions());
    $this->assistant->assignRole($invented);

    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    // Confined to the very course every route below touches, so «outside your
    // scope» cannot be the refusal being measured.
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 25000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

/*
|--------------------------------------------------------------------------
| الحائط — every case here goes red when `Gate::before` is deleted
|--------------------------------------------------------------------------
*/

it('refuses the assistant a settlement statement they hold the permission for', function (): void {
    Sanctum::actingAs($this->assistant);

    // `StatementController::show` authorises BEFORE it resolves a teacher
    // profile, so this is a 403 and not the 404 an assistant would otherwise get
    // for having no profile — the same green for a different reason.
    $this->getJson('/api/v1/settlement/statement')->assertForbidden();
    $this->getJson('/api/v1/settlement/units')->assertForbidden();
});

it('refuses the assistant a settlement rate request', function (): void {
    Sanctum::actingAs($this->assistant);

    // ⚠️ A VALID BODY, because a Form Request validates BEFORE the controller
    // authorises: an incomplete payload answers 422 whatever the wall does, and
    // the assertion would be measuring the validator.
    $this->postJson('/api/v1/settlement/rate-requests', [
        'session_type' => 'individual',
        'requested_amount_minor' => 6000,
    ])->assertForbidden();
});

it('shows the assistant none of the workspace orders while the owner still sees them all', function (): void {
    /*
    | ⚠️ 200 WITH ZERO ROWS, NOT 403 — and this is the sharpest assertion in the
    | file. `OrderController::index()` falls back to «your own orders» for a
    | reader without ORDERS_VIEW_ALL, so the wall shows up as an EMPTY list
    | rather than as a refusal. Delete the hook and this count becomes 1,
    | visibly, on a screen the assistant can already open.
    */
    Sanctum::actingAs($this->assistant);

    $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');

    // The other direction: the route works, the money is there, and the owner
    // reads it. Without this half an empty list could just be a broken endpoint.
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses the assistant one order by its uuid, which is what an address bar asks', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/orders/{$this->order->uuid}")->assertForbidden();
});

it('leaves every walled name out of the permission list the sidebar is built from', function (): void {
    /*
    | ⚠️ THE ONE READ PATH THAT COULD HAVE MISSED THE WALL, AND IT DOES NOT.
    | `UserResource::grantedPermissions()` walks `Permissions::all()` through
    | `$user->can()` rather than through spatie's `getAllPermissions()`, so the
    | refusal reaches it — measured here rather than assumed, because the spatie
    | accessors pluck names off the roles directly and would have walked straight
    | past it. The consequence of getting it wrong is not a leak: it is a sidebar
    | offering the assistant five financial screens that each answer 403, which
    | reads as a broken product and teaches them the app does not know who they
    | are. Exactly the defect the note in that method already records for the
    | super admin, reached from the other side.
    */
    Sanctum::actingAs($this->assistant);

    $granted = $this->getJson('/api/v1/auth/me')->assertOk()->json('permissions');

    expect($granted)->toBeArray()
        // The control: the assistant DOES hold things, so an empty list would
        // satisfy the negative assertion below while proving nothing.
        ->toContain(Permissions::LESSONS_MANAGE);

    // Both halves: the six a role could legally have been given and the two the
    // platform took. The second pair is not redundant — `grantedPermissions()`
    // walks `Permissions::all()`, so a name nobody can be granted still has to
    // come back absent rather than, say, defaulting to allowed.
    foreach ([...walledTenantPermissions(), ...walledPlatformPermissions()] as $walled) {
        expect($granted)->not->toContain($walled);
    }
});

/*
|--------------------------------------------------------------------------
| ت-١ — the one financial permission an owner may delegate on purpose
|--------------------------------------------------------------------------
*/

it('refuses the student balance panel to an assistant who was never granted it', function (): void {
    /*
    | ⚠️ THIS CASE STAYS GREEN WHEN THE WALL IS DELETED, AND THAT IS CORRECT.
    | `billing.balance.view` left `assistant-teacher` in Phase 1, so the refusal
    | here is the ordinary absence of a permission and not the wall. It sits in
    | this file because FR-003 is about the SURFACE: a reader asking whether the
    | balance panel is closed should find the answer beside the rest of them.
    */
    Sanctum::actingAs($this->assistant);

    $this->getJson('/api/v1/manage/billing/students')->assertForbidden();
});

it('opens the student balance panel to an assistant the owner granted it deliberately', function (): void {
    /*
    | ⚠️ THE WALL MUST NOT EAT THE EXCEPTION. `billing.balance.view` is excluded
    | from the derived set on purpose (ت-١): credits and withheld state carry no
    | money (StudentBalanceAllowlist), and the 006 argument — the assistant who
    | schedules needs to know who can book — is still true. Which is why Phase 1
    | MOVED it to $teacher rather than deleting it: the owner ticks it back, one
    | named person at a time. Without this assertion a wall built on the bare
    | prefixes would pass everything else in the file and quietly break the grant.
    */
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    Role::query()
        ->where('name', 'مصحّح')
        ->firstOrFail()
        ->syncPermissions([...walledTenantPermissions(), Permissions::BILLING_BALANCE_VIEW]);

    Sanctum::actingAs($this->assistant);

    $this->getJson('/api/v1/manage/billing/students')->assertOk();
});

/*
|--------------------------------------------------------------------------
| ما انتقلَ إلى نموذجِ الصلاحيّاتِ نفسِه — لم يعدْ يقيسُ الحائط
|--------------------------------------------------------------------------
*/

it('refuses the assistant the approval and the rejection of a payment', function (): void {
    /*
    | ⚠️ THIS CASE SAT UNDER «الحائط» AND NO LONGER BELONGS THERE, WHICH IS THE
    | POINT OF MOVING IT RATHER THAN LEAVING IT WHERE IT READ CORRECTLY. The
    | fixture above used to hand the assistant `payments.approve` so that a 403
    | could only be the hook; the platform took both names, `Tenancy\Models\Role`
    | refuses them on any workspace role, and the refusal here is now the
    | ORDINARY absence of a permission nobody in a workspace can hold.
    |
    | So it stays green with the hook deleted — exactly what this file's two-group
    | contract says must not happen inside «الحائط», and what `T054`'s «أعِده»
    | would otherwise report as the wall still standing.
    |
    | ⚠️ AND IT IS KEPT, NOT DELETED. A surface that must refuse is still worth
    | measuring even when the reason moved a layer down: `platformReads()` and
    | `OrderPolicy`'s platform branch are one edit away from letting a workspace
    | reader back in, and this is the case that would go red.
    */
    Sanctum::actingAs($this->assistant);

    $this->postJson("/api/v1/orders/{$this->order->uuid}/approve")->assertForbidden();
    $this->postJson("/api/v1/orders/{$this->order->uuid}/reject", [
        'reason' => 'الإيصال غير واضح',
    ])->assertForbidden();
});
