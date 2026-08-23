<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-003 on the SECOND door — `/admin`.
|
| ⚠️ THE WALL IS NOT MEASURED BY THE API ALONE. `EnsureFilamentAccess` admits
| tenant staff BY ROLE NAME, and `assistant-teacher` is on that list — so every
| assistant on the platform can already open the panel. A financial rule proved
| only over `/api/v1` is a rule with a whole second entrance behind it.
|
| ⚠️ AND BOTH DIRECTIONS, WHICH IS THE `981ca23` LESSON. `OrderPolicy::view()`
| cuts credit purchases away from a teacher uuid by uuid, and a Filament LIST
| never calls it — the only gate a table has is `viewAny()` plus its own query.
| A test that measured the refusal alone would stay green against a resource
| closed to everybody, which is the mirror defect and just as broken.
|
| The environment is forced to `local` for the reason `PermissionPanelTest`
| records: `Filament\Http\Middleware\Authenticate` aborts 403 for any user that
| does not implement `FilamentUser` unless the environment is local, and this
| product's panel rule lives in `EnsureFilamentAccess`, which runs after it. In
| `testing` the panel refuses everybody — including the owner this file needs to
| see a list.
*/

beforeEach(function (): void {
    config(['app.env' => 'local']);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    // Same fixture shape as the API wall: the assistant HOLDS the permission the
    // screen asks for, so a refusal cannot be the ordinary absence of one.
    $invented = Role::query()->create([
        'name' => 'مصحّح',
        'guard_name' => 'web',
        'team_id' => $this->workspace->getKey(),
    ]);
    $invented->syncPermissions([Permissions::ORDERS_VIEW_ALL, Permissions::PAYMENTS_APPROVE]);
    $this->assistant->assignRole($invented);

    AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 25000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);
});

it('lets the assistant through the panel door and refuses them the orders screen', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->assistant);
    $this->actingAs($this->assistant);

    // The door is open — which is what makes the next line a statement about the
    // orders screen rather than about `EnsureFilamentAccess`.
    $this->get('/admin/courses')->assertOk();

    $this->get('/admin/orders')->assertForbidden();

    expect(OrderResource::canViewAny())->toBeFalse();
});

it('still shows the owner the orders screen', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->actingAs($this->owner);

    $this->get('/admin/orders')->assertOk();

    expect(OrderResource::canViewAny())->toBeTrue();
});
