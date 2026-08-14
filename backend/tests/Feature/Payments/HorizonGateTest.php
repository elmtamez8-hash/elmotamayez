<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Gate;

/*
| Who may watch the queue that now carries the platform's money.
|
| ⚠️ THE GATE COMPARED AGAINST AN EMPTY ARRAY UNTIL THIS PHASE, so the answer was
| always no and `/horizon` was unreachable everywhere but local — while the
| payment sweep, the callback processing and the charge listener all ran on it. A
| stuck worker meant money that settled and was never credited, with the one
| screen that would show it locked.
|
| Tested because a gate with no test is a gate whose next edit nobody notices,
| and this one has already been wrong once for the whole life of the product.
*/

it('opens the queue dashboard to the platform and to nobody else', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $platform = User::factory()->create(['is_super_admin' => true]);

    expect(Gate::forUser($platform)->allows('viewHorizon'))->toBeTrue()
        // The workspace owner holds every tenant permission there is, and the
        // queue is not one of them: it carries every workspace's payments.
        ->and(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($student)->allows('viewHorizon'))->toBeFalse()
        // And a guest, which is what the dashboard faces on the open internet.
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});
