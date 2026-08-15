<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use Laravel\Sanctum\Sanctum;

/*
| FR-015. Who may read across teachers, and what "across" has to mean.
|
| ⚠️ EVERY TEST HERE BUILDS TWO WORKSPACES, AND A ONE-WORKSPACE FIXTURE WOULD
| PASS THE WRONG TEST. `WorkspaceContext::id()` falls back to
| `users.last_workspace_id` for a super admin as well as anybody else, so a
| platform report left inside the tenant scope returns ONE teacher's numbers and
| calls them the platform's — and with a single workspace in the database, that
| is indistinguishable from the correct answer.
*/

beforeEach(function (): void {
    [$this->first, $this->firstOwner] = $this->createWorkspaceWithOwner();
    [$this->second, $this->secondOwner] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->second, $this->secondOwner);
    sitQuestion($this->second, bankQuestion($this->second, null, ['content' => 'سؤال المدرّسة الثانية؟']), correct: 2, wrong: 8);

    $this->setCurrentWorkspace($this->first, $this->firstOwner);
    sitQuestion($this->first, bankQuestion($this->first, null, ['content' => 'سؤال المدرّس الأوّل؟']), correct: 9, wrong: 1);

    RollUpQuestionStatsJob::dispatch();
});

it('shows a teacher their own questions and nobody else’s', function (): void {
    Sanctum::actingAs($this->firstOwner);

    $response = $this->getJson('/api/v1/manage/analytics/questions');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('question.content')->all())
        ->toBe(['سؤال المدرّس الأوّل؟']);
});

it('refuses the platform view to a teacher who asks for it', function (): void {
    Sanctum::actingAs($this->firstOwner);

    // The permission is held by no tenant role at all, so the owner of a
    // workspace is exactly the person this must refuse.
    $this->getJson('/api/v1/manage/analytics/questions?scope=platform')->assertForbidden();
    $this->getJson('/api/v1/manage/analytics/concepts?scope=platform')->assertForbidden();
});

it('shows both teachers to a reader who holds the platform permission', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/manage/analytics/questions?scope=platform');

    $response->assertOk();

    $contents = collect($response->json('data'))->pluck('question.content')->all();

    expect($contents)->toHaveCount(2)
        ->and($contents)->toContain('سؤال المدرّس الأوّل؟')
        ->and($contents)->toContain('سؤال المدرّسة الثانية؟')
        ->and($response->json('meta.scope'))->toBe('platform');

    // The same question of the concept rollup, because it is a SECOND reader of
    // the same bypass: `relation()` is shared, and a shared helper is exactly
    // what makes one caller's missing closure invisible.
    $concepts = $this->getJson('/api/v1/manage/analytics/concepts?scope=platform')->json('data');

    expect($concepts)->toHaveCount(2)
        ->and(collect($concepts)->pluck('concept')->filter()->count())->toBe(2);
});

it('resolves the question behind every platform row rather than nulling it', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    Sanctum::actingAs($admin);

    $rows = $this->getJson('/api/v1/manage/analytics/questions?scope=platform')->json('data');

    /*
    | ⚠️ THE BYPASS IS PER MODEL, AND THIS IS THE HALF THAT SHIPPED BROKEN ONCE
    | BEFORE (docs · the audit chain). `withoutWorkspaceScope()` on the outer
    | query does nothing for `->with('question')`: the relation runs its own
    | query under Question's global scope, so every row outside the reader's
    | fallback workspace comes back with a null question and the report answers
    | "nothing here" with a 200.
    */
    expect(collect($rows)->pluck('question')->filter()->count())->toBe(2)
        ->and(collect($rows)->pluck('question.concept')->filter()->count())->toBe(2);
});
