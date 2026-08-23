<?php

declare(strict_types=1);

use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Enums\TermPolicy;
use App\Modules\Community\Models\BlockedTerm;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-020 — the term list, and the three policies that are not one policy.
|
| ⚠️ MATCHING IS ON WORD BOUNDARIES, NEVER ON CONTAINMENT, and Arabic is where
| that goes wrong quietly. A containment test refuses «الممنوعات» for holding
| «ممنوع» and refuses «مساحة» for holding a three-letter term inside it — and
| over-blocking is precisely what teaches a room to write ﻣ.ﻣ.ﻧ.ﻭ.ﻉ and route
| around the filter for good. Both directions in one test: the bare word is
| caught, the longer word carrying it is not.
|
| ⚠️ AND THE FIXTURE IS ARABIC ON PURPOSE. PCRE's `\b` is defined through `\w`,
| which is ASCII unless the pattern is compiled with UCP — so a filter written
| with `\b` can permit EVERYTHING silently, which is the worst shape an absent
| guard can take. `TermFilter` uses `\p{L}` lookarounds instead, and this file is
| what would fail if anybody swapped them back.
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

    $this->url = "/api/v1/conversations/{$this->conversationUuid}/messages";

    $this->term = function (string $term, TermPolicy $policy): void {
        BlockedTerm::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'term' => $term,
            'policy' => $policy,
        ]);
    };
});

it('seeds a workspace with a starting term list rather than leaving the filter inert', function (): void {
    /*
    | ⚠️ A FILTER THAT PERMITS EVERYTHING IN SILENCE IS THE WORST FORM OF ABSENCE.
    | The list is seeded on `WorkspaceCreated` — and the backfill migration is what
    | reaches every workspace that already existed, which no fixture can see
    | because every fixture creates its workspace after the change.
    */
    expect(BlockedTerm::query()->where('workspace_id', $this->workspace->getKey())->count())
        ->toBeGreaterThan(0);
});

it('refuses a message on a bare blocked word and lets a longer word carrying it through', function (): void {
    ($this->term)('بليد', TermPolicy::Block);

    Sanctum::actingAs($this->student);

    $this->postJson($this->url, ['body' => 'أنت بليد'])->assertStatus(422);

    // ⚠️ THE OTHER DIRECTION. «البليدة» contains the term and is a different word;
    // a containment filter refuses it, and the room learns to route around the
    // filter rather than to stop insulting each other.
    $this->postJson($this->url, ['body' => 'البليدة فكرة في الرواية'])->assertCreated();
});

it('masks a term instead of refusing the message', function (): void {
    ($this->term)('0501234567', TermPolicy::Mask);

    Sanctum::actingAs($this->student);

    $body = (string) $this->postJson($this->url, [
        'body' => 'راسلني على 0501234567 من فضلك',
    ])->assertCreated()->json('body');

    expect($body)->not->toContain('0501234567')
        // The message still says what it meant — that is the whole difference
        // between masking and blocking.
        ->and($body)->toContain('راسلني على')
        ->and($body)->toContain('من فضلك');
});

it('delivers a review term and raises a row for a human', function (): void {
    ($this->term)('اجتماع', TermPolicy::Review);

    Sanctum::actingAs($this->student);

    $this->postJson($this->url, ['body' => 'هل نرتّب اجتماع خارج المنصّة؟'])->assertCreated();

    $this->getJson($this->url)->assertOk()->assertJsonCount(1);

    expect(ModerationAction::query()->withoutWorkspaceScope()
        ->where('workspace_id', $this->workspace->getKey())
        ->where('verdict', ModerationVerdict::Reported->value)
        ->count())->toBe(1);
});

it('keeps one workspace term list out of another workspace', function (): void {
    ($this->term)('بليد', TermPolicy::Block);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'أخرى']);
    $otherCourse = Course::factory()->create(['workspace_id' => $other->getKey()]);
    $otherStudent = $this->addWorkspaceMember($other, Roles::STUDENT);
    $this->createEnrollment($other, $otherCourse, $otherStudent);

    // Clear the seeded list there so the only term in play is the one filed under
    // the first workspace.
    BlockedTerm::query()->where('workspace_id', $other->getKey())->delete();

    Sanctum::actingAs($otherStudent);

    $uuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $other->uuid,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'أنت بليد'])->assertCreated();
});
