<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| SC-009 · NFR-010 — opening a chat costs the same number of queries whatever is
| in it, and so does the conversation list.
|
| ⚠️ TWO SIZES, OR IT IS NOT A MEASUREMENT. A single run tells you a number and
| nothing about whether it grows; the defect this guards is an N+1 inside a
| Resource, which is invisible at one row and linear at fifty.
|
| ⚠️ A WARM-UP FIRST. spatie's permission cache, the `platform_settings`
| `rememberForever` reads and the route/policy resolution all populate on the
| first request of a test, so an unwarmed first call is several queries more
| expensive than the second for reasons that have nothing to do with the page.
| Without it the two sizes differ and the test fails on its own scaffolding.
|
| ⚠️ AND IT ASSERTS THE FIELDS ARE PRESENT AS WELL AS THAT THE COUNT IS FLAT.
| Dropping the eager load produces no N+1 when the Resource uses `whenLoaded` —
| the key is simply ABSENT, the page is CHEAPER, and a test measuring queries
| alone reports the regression as an improvement while the screen lists messages
| with nobody's name on them. Spec 013's officer queue already shipped that once.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    Sanctum::actingAs($this->student);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');
});

/*
| Post `$count` messages, alternating sender so the sender join has work to do.
|
| A closure on the test case rather than a `function` declaration: a function
| defined in a test file is a GLOBAL that exists only when that file happens to
| load first, which is the trap `countingQueries()` was moved to `tests/Pest.php`
| to avoid. This one is used nowhere else, so it stays local by construction.
*/
$fill = function (string $conversationUuid, int $count, User $student, User $owner): void {
    for ($i = 0; $i < $count; $i++) {
        Sanctum::actingAs($i % 2 === 0 ? $student : $owner);
        test()->postJson("/api/v1/conversations/{$conversationUuid}/messages", [
            'body' => 'رسالة رقم '.$i,
        ])->assertCreated();
    }
};

it('reads a conversation at a constant cost whatever its length', function () use ($fill): void {
    $fill($this->conversationUuid, 4, $this->student, $this->owner);

    Sanctum::actingAs($this->student);
    $url = "/api/v1/conversations/{$this->conversationUuid}/messages";

    // Warm-up — see the banner. Its result is discarded on purpose.
    $this->getJson($url)->assertOk();

    [$small, $smallResponse] = countingQueries(fn () => $this->getJson($url)->assertOk());

    $fill($this->conversationUuid, 40, $this->student, $this->owner);

    Sanctum::actingAs($this->student);
    [$large, $largeResponse] = countingQueries(fn () => $this->getJson($url)->assertOk());

    expect($large)->toBe($small);

    // The field half: a sender name on every row, at both sizes. Without this a
    // dropped eager load reads as a saving.
    foreach ([$smallResponse, $largeResponse] as $response) {
        $names = $response->json('*.sender_name');
        expect($names)->not->toBeEmpty()
            ->and(array_filter($names, fn ($name) => $name === null || $name === ''))->toBe([]);
    }
});

it('lists conversations at a constant cost whatever their number', function () use ($fill): void {
    /*
    | The teacher's side, because that is the list that grows: one conversation
    | per student. Each carries its last message and the name of whoever sent it,
    | which is the join an N+1 hides in.
    */
    $fill($this->conversationUuid, 2, $this->student, $this->owner);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/conversations')->assertOk();

    [$small, $smallResponse] = countingQueries(fn () => $this->getJson('/api/v1/conversations')->assertOk());

    // Nine more students, each with their own conversation and a message in it.
    for ($i = 0; $i < 9; $i++) {
        $this->setCurrentWorkspace($this->workspace, $this->owner);
        $extra = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $extra);

        Sanctum::actingAs($extra);
        $uuid = (string) $this->postJson('/api/v1/conversations', [
            'workspace' => $this->workspace->uuid,
        ])->assertCreated()->json('uuid');

        $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'سؤال'])->assertCreated();
    }

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    [$large, $largeResponse] = countingQueries(fn () => $this->getJson('/api/v1/conversations')->assertOk());

    expect($large)->toBe($small)
        ->and($smallResponse->json())->toHaveCount(1)
        ->and($largeResponse->json())->toHaveCount(10);

    foreach ($largeResponse->json() as $row) {
        expect($row['last_message'])->not->toBeNull()
            ->and($row['last_message']['sender_name'])->not->toBeEmpty();
    }
});
