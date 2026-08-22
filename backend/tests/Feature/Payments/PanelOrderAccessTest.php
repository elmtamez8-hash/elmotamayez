<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Policies\OrderPolicy;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;

/*
| Who may read a list of orders — asked of the PANEL, not of the API.
|
| ⚠️ THE API AND THE PANEL ANSWER THIS QUESTION THROUGH DIFFERENT DOORS, AND ONLY
| ONE OF THEM WAS GUARDED. `OrderController::index()` filters by hand and calls no
| policy; `OrderResource` declares no `canViewAny()` and therefore falls back to
| `OrderPolicy::viewAny()`, which returned an unconditional `allow()`. Meanwhile
| `EnsureFilamentAccess` admits `assistant-teacher` to `/admin` by role name. So an
| assistant read every student's email beside the amount they paid, with zero
| configuration — while `OrderPolicy::view()` guards that same fact uuid by uuid
| three lines above.
|
| ⚠️ AND A FILAMENT TABLE NEVER CALLS `view()`. The row-level ability is not
| consulted for a list, which is why the credit-purchase cut has to be repeated on
| the query rather than inherited from the policy that already makes it.
|
| Spec 010's FR-003 — "an assistant reaches no financial data at all" — is what
| sent somebody looking. It was already broken before the phase that enforces it
| had started.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
});

it('refuses the panel order list to an assistant', function (): void {
    Auth::login($this->assistant);

    expect(app(OrderPolicy::class)->viewAny($this->assistant)->allowed())->toBeFalse();
});

it('still shows the list to the teacher who owns the workspace', function (): void {
    Auth::login($this->owner);

    /*
    | The ALLOW direction, because a deny-only assertion passes just as well
    | against a policy that refuses everybody — and a refusal that reaches the
    | teacher takes away the screen they run their business from.
    */
    expect(app(OrderPolicy::class)->viewAny($this->owner)->allowed())->toBeTrue();
});

it('keeps the platform s credit sales out of the teacher s panel list', function (): void {
    Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => null,
        'kind' => OrderKind::Credits,
        'amount_minor' => 30_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    $course = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => null,
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'under_review',
    ]);

    Auth::login($this->owner);

    $visible = OrderResource::getEloquentQuery()->pluck('id')->all();

    /*
    | A credit purchase is a sale between the student and the PLATFORM (Q-4), and
    | two totals across two package sizes solve for the platform's constants — the
    | inference `billing.collection.view` exists to hold. `OrderController::index()`
    | makes this exact cut; the panel made none.
    */
    expect($visible)->toBe([$course->getKey()]);
});
