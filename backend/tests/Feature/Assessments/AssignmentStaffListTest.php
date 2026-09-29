<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| The teacher's homework list (`GET /assignments`, the `assignments.manage`
| branch): paged on the server, searched and filtered by status there, with
| per-status totals in `meta.counts` — and, for a confined assistant, the
| homework of their own courses only (spec 010 · FR-005).
|
| ⚠️ BOTH DIRECTIONS IN EVERY SCOPE TEST: «far is absent» alone is green against
| an assistant who sees nothing at all.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearDraft = staffListAssignment($this->near, 'واجب الجبر', Assignment::STATUS_DRAFT);
    $this->nearLive = staffListAssignment($this->near, 'واجب الهندسة', Assignment::STATUS_PUBLISHED);
    $this->farLive = staffListAssignment($this->far, 'واجب الجبر البعيد', Assignment::STATUS_PUBLISHED);
    $this->loose = staffListAssignment(null, 'واجب لكل الطلاب', Assignment::STATUS_DRAFT);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::ASSIGNMENTS_MANAGE);

    $this->assistantAssignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function staffListAssignment(?Course $course, string $title, string $status): Assignment
{
    return Assignment::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => $course?->getKey(),
        'title' => $title,
        'status' => $status,
        'published_at' => $status === Assignment::STATUS_PUBLISHED ? now() : null,
        'created_by' => test()->owner->getKey(),
    ]);
}

/** @return array{uuids: list<string>, meta: array<string, mixed>} */
function staffListRead(string $query = ''): array
{
    $response = test()->getJson('/api/v1/assignments'.($query === '' ? '' : '?'.$query))->assertOk();

    return [
        'uuids' => collect($response->json('data'))->pluck('uuid')->all(),
        'meta' => $response->json('meta'),
    ];
}

it('pages the list on the server and says how many pages there are', function (): void {
    Sanctum::actingAs($this->owner);

    $first = staffListRead('per_page=5');

    expect($first['meta'])->toMatchArray(['total' => 4, 'current_page' => 1, 'last_page' => 1])
        ->and($first['uuids'])->toHaveCount(4);

    foreach (range(1, 4) as $i) {
        staffListAssignment($this->near, "واجب إضافي {$i}", Assignment::STATUS_DRAFT);
    }

    $pageOne = staffListRead('per_page=5&page=1');
    $pageTwo = staffListRead('per_page=5&page=2');

    expect($pageOne['meta'])->toMatchArray(['total' => 8, 'current_page' => 1, 'last_page' => 2])
        ->and($pageOne['uuids'])->toHaveCount(5)
        ->and($pageTwo['uuids'])->toHaveCount(3)
        ->and(array_intersect($pageOne['uuids'], $pageTwo['uuids']))->toBe([]);

    // The counts span the whole list, not the five rows on this page.
    expect($pageOne['meta']['counts'])->toBe(['draft' => 6, 'published' => 2]);
});

it('narrows by status on the server while the counts keep both chips', function (): void {
    Sanctum::actingAs($this->owner);

    $drafts = staffListRead('status=draft');

    expect($drafts['uuids'])->toEqualCanonicalizing([$this->nearDraft->uuid, $this->loose->uuid])
        ->and($drafts['meta']['total'])->toBe(2)
        ->and($drafts['meta']['counts'])->toBe(['draft' => 2, 'published' => 2]);

    expect(staffListRead('status=published')['uuids'])
        ->toEqualCanonicalizing([$this->nearLive->uuid, $this->farLive->uuid]);
});

it('searches the title on the server, and the counts follow the search', function (): void {
    Sanctum::actingAs($this->owner);

    $found = staffListRead('q='.urlencode('الجبر'));

    expect($found['uuids'])->toEqualCanonicalizing([$this->nearDraft->uuid, $this->farLive->uuid])
        ->and($found['meta']['counts'])->toBe(['draft' => 1, 'published' => 1]);

    expect(staffListRead('q='.urlencode('الجبر').'&status=published')['uuids'])->toBe([$this->farLive->uuid]);
});

it('refuses a status that does not exist rather than returning everything', function (): void {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/assignments?status=archived')->assertUnprocessable();
});

it('lists and counts only a confined assistant\'s own courses', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assistantAssignment->getKey(),
        'course_id' => $this->near->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant);

    $read = staffListRead();

    // Near present, far and course-less absent — and the counts agree.
    expect($read['uuids'])->toEqualCanonicalizing([$this->nearDraft->uuid, $this->nearLive->uuid])
        ->and($read['meta']['total'])->toBe(2)
        ->and($read['meta']['counts'])->toBe(['draft' => 1, 'published' => 1]);

    expect(staffListRead('q='.urlencode('الجبر'))['uuids'])->toBe([$this->nearDraft->uuid]);
});

it('lists everything for an unconfined assistant and for the owner', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        $read = staffListRead();

        expect($read['uuids'])->toEqualCanonicalizing([
            $this->nearDraft->uuid, $this->nearLive->uuid, $this->farLive->uuid, $this->loose->uuid,
        ])->and($read['meta']['counts'])->toBe(['draft' => 2, 'published' => 2]);
    }
});

it('never lists or counts another workspace\'s homework', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace($other, fn (): Assignment => Assignment::factory()->create([
        'workspace_id' => $other->getKey(),
        'title' => 'واجب الجبر الغريب',
        'status' => Assignment::STATUS_PUBLISHED,
        'published_at' => now(),
        'created_by' => $otherOwner->getKey(),
    ]));

    Sanctum::actingAs($this->owner);

    $read = staffListRead('q='.urlencode('الجبر'));

    expect($read['uuids'])->not->toContain($foreign->uuid)
        ->and($read['meta']['counts'])->toBe(['draft' => 1, 'published' => 1]);
});

it('gives a student no drafts and no counts, whatever status they ask for', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    Sanctum::actingAs(User::query()->findOrFail($student->getKey()));

    $response = $this->getJson('/api/v1/assignments?status=draft')->assertOk();

    expect(collect($response->json('data'))->pluck('uuid')->all())
        ->not->toContain($this->nearDraft->uuid)
        ->not->toContain($this->loose->uuid)
        ->and($response->json('meta'))->not->toHaveKey('counts');
});
