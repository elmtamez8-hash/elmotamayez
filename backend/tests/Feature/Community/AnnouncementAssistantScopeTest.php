<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on announcements. `announcements.manage` is not on the
| default assistant role; when the owner ticks it onto one, that assistant could
| address the WHOLE workspace (`scope=all`) or any course, session or group in
| it, and list, edit, publish and withdraw every announcement there.
|
| A confined assistant now addresses only their own courses — a course of
| theirs, a session of one, a group of one; never `all` — and lists and acts
| on only the announcements they could have written. Both directions in every
| test, and the unconfined assistant and the owner are unchanged.
*/

beforeEach(function (): void {
    // Every door of every target, many times over: `throttle:authoring` (60 a
    // minute per user) is not what this file measures.
    $this->withoutMiddleware(ThrottleRequests::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $ws = $this->workspace->getKey();

    $this->nearCourse = Course::factory()->create(['workspace_id' => $ws]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $ws]);
    $this->nearSession = ClassSession::factory()->create(['workspace_id' => $ws, 'course_id' => $this->nearCourse->getKey()]);
    $this->farSession = ClassSession::factory()->create(['workspace_id' => $ws, 'course_id' => $this->farCourse->getKey()]);
    $this->nearCohort = Cohort::factory()->create(['workspace_id' => $ws, 'course_id' => $this->nearCourse->getKey()]);
    $this->farCohort = Cohort::factory()->create(['workspace_id' => $ws, 'course_id' => $this->farCourse->getKey()]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ws);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::ANNOUNCEMENTS_MANAGE);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function anmConfine(): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => test()->nearCourse->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** Every target the form offers, as `scope => uuid|null`, keyed by a label. */
function anmTargets(string $side): array
{
    $t = test();

    return $side === 'near'
        ? [
            'course' => [Announcement::SCOPE_COURSE, $t->nearCourse->uuid],
            'session' => [Announcement::SCOPE_SESSION, $t->nearSession->uuid],
            'cohort' => [Announcement::SCOPE_COHORT, $t->nearCohort->uuid],
        ]
        : [
            'all' => [Announcement::SCOPE_ALL, null],
            'course' => [Announcement::SCOPE_COURSE, $t->farCourse->uuid],
            'session' => [Announcement::SCOPE_SESSION, $t->farSession->uuid],
            'cohort' => [Announcement::SCOPE_COHORT, $t->farCohort->uuid],
        ];
}

/** @return array<string, int> the status each create answered */
function anmCreate(User $author, array $targets): array
{
    Sanctum::actingAs($author);

    return collect($targets)->map(fn (array $target): int => test()->postJson('/api/v1/manage/announcements', array_filter([
        'body' => 'تنبيه للطلاب.',
        'scope' => $target[0],
        'scope_uuid' => $target[1],
    ]))->getStatusCode())->all();
}

/** An announcement written by the owner at this target — the row the list and the doors ask about. */
function anmRow(array $target): Announcement
{
    $t = test();
    $id = match ($target[0]) {
        Announcement::SCOPE_COURSE => Course::query()->where('uuid', $target[1])->value('id'),
        Announcement::SCOPE_SESSION => ClassSession::query()->withoutWorkspaceScope()->where('uuid', $target[1])->value('id'),
        Announcement::SCOPE_COHORT => Cohort::query()->withoutWorkspaceScope()->where('uuid', $target[1])->value('id'),
        default => null,
    };

    $row = Announcement::factory()->create([
        'workspace_id' => $t->workspace->getKey(),
        'author_user_id' => $t->owner->getKey(),
        'scope' => $target[0],
    ]);
    $row->forceFill(['scope_id' => $id])->save();

    return $row;
}

/** @return list<string> */
function anmListed(User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson('/api/v1/manage/announcements')->assertOk()->json('data'))->pluck('uuid')->all();
}

/** @return array<string, int> */
function anmDoors(User $actor, Announcement $row): array
{
    Sanctum::actingAs($actor);
    $t = test();

    return [
        'update' => $t->patchJson("/api/v1/manage/announcements/{$row->uuid}", ['body' => 'نص معدّل.', 'scope' => $row->scope])->getStatusCode(),
        'publish' => $t->postJson("/api/v1/manage/announcements/{$row->uuid}/publish")->getStatusCode(),
        'destroy' => $t->deleteJson("/api/v1/manage/announcements/{$row->uuid}")->getStatusCode(),
    ];
}

it('lets a confined assistant address their own course, session and group, and nothing else', function (): void {
    anmConfine();

    expect(anmCreate($this->assistant, anmTargets('near')))->toBe(['course' => 201, 'session' => 201, 'cohort' => 201])
        ->and(anmCreate($this->assistant, anmTargets('far')))->toBe(['all' => 403, 'course' => 403, 'session' => 403, 'cohort' => 403]);

    // Nothing was written for a refused target.
    expect(Announcement::query()->where('author_user_id', $this->assistant->getKey())->count())->toBe(3);
});

it('lists and opens for a confined assistant only the announcements they could have written', function (): void {
    anmConfine();

    $near = collect(anmTargets('near'))->map(fn (array $target): Announcement => anmRow($target));
    $far = collect(anmTargets('far'))->map(fn (array $target): Announcement => anmRow($target));

    expect(anmListed($this->assistant))->toEqualCanonicalizing($near->pluck('uuid')->all());

    foreach ($far as $row) {
        expect(anmDoors($this->assistant, $row))->toBe(['update' => 403, 'publish' => 403, 'destroy' => 403]);
    }

    foreach ($near as $row) {
        expect(anmDoors($this->assistant, $row))->toBe(['update' => 200, 'publish' => 200, 'destroy' => 200]);
    }
});

it('leaves an unconfined assistant and the owner every target and every announcement', function (): void {
    $rows = collect([...array_values(anmTargets('near')), ...array_values(anmTargets('far'))])
        ->map(fn (array $target): Announcement => anmRow($target));

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(anmListed($actor))->toEqualCanonicalizing($rows->pluck('uuid')->all());
    }

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(anmCreate($actor, anmTargets('far')))->toBe(['all' => 201, 'course' => 201, 'session' => 201, 'cohort' => 201]);
    }

    expect(anmDoors($this->assistant, $rows->last()))->toBe(['update' => 200, 'publish' => 200, 'destroy' => 200]);
});

it('never lists another workspace\'s announcements', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    Announcement::factory()->create(['workspace_id' => $other->getKey(), 'author_user_id' => $otherOwner->getKey()]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $mine = anmRow(anmTargets('far')['all']);

    expect(anmListed($this->owner))->toBe([(string) $mine->uuid]);
});
