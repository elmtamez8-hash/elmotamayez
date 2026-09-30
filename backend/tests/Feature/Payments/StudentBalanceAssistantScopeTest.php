<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the balances panel. `billing.balance.view` is not on the
| default assistant role — the owner ticks it onto one named assistant — and
| until 2026-09-30 that assistant then read every student of every course in
| the workspace, however narrowly they were confined.
|
| A confined assistant now reads the rows of their own courses, and the
| `withheld_students` card counts the people that table lists.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->nearCourse = billingCourse($this->workspace);
    $this->farCourse = billingCourse($this->workspace);

    // `near` studies in the near course; `far` only in the far one — and both
    // are WITHHELD there, so the card has someone to miscount.
    $this->near = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->far = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->nearCourse, $this->near);
    $this->createEnrollment($this->workspace, $this->farCourse, $this->far);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    billingBalance($this->workspace, $this->near, $this->nearCourse)->forceFill(['remaining_credits' => -1])->save();
    billingBalance($this->workspace, $this->far, $this->farCourse)->forceFill(['remaining_credits' => -2])->save();

    // Another workspace's withheld student, whom nobody here ever sees.
    [$other] = $this->createWorkspaceWithOwner();
    $otherCourse = billingCourse($other);
    $stranger = $this->addWorkspaceMember($other, Roles::STUDENT);
    $this->createEnrollment($other, $otherCourse, $stranger);
    billingBalance($other, $stranger, $otherCourse)->forceFill(['remaining_credits' => -4])->save();

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::BILLING_BALANCE_VIEW);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

/** @return array{0: list<string>, 1: int, 2: int} student uuids, total, withheld */
function sbPanel(User $reader): array
{
    Sanctum::actingAs($reader);
    app()->forgetScopedInstances();

    $response = test()->getJson('/api/v1/manage/billing/students')->assertOk();

    return [
        collect($response->json('data'))->pluck('student_uuid')->all(),
        (int) $response->json('meta.total'),
        (int) $response->json('meta.withheld_students'),
    ];
}

it('shows a confined assistant the balances of their own courses, and counts only those', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->nearCourse->getKey(),
    ]);

    [$uuids, $total, $withheld] = sbPanel($this->assistant);

    expect($uuids)->toBe([(string) $this->near->uuid])
        ->and($total)->toBe(1)
        ->and($withheld)->toBe(1);
});

it('leaves an unconfined assistant and the owner the whole workspace, and never another one', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        [$uuids, $total, $withheld] = sbPanel($reader);

        expect($uuids)->toEqualCanonicalizing([(string) $this->near->uuid, (string) $this->far->uuid])
            ->and($total)->toBe(2)
            ->and($withheld)->toBe(2);
    }
});
