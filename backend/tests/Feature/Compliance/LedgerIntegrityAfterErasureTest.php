<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;

/**
 * SC-007 — the erasure changes no total and breaks no workspace.
 *
 * ⚠️ THE CASE IS A WORKSPACE OWNER, NOT A STUDENT, AND THAT IS THE WHOLE POINT.
 * `workspaces.owner_user_id` is `cascadeOnDelete()`, and every other table in the
 * product carries `workspace_id` with NO foreign key behind it. So DELETING a
 * teacher's account destroys their workspace and leaves the courses, sessions,
 * enrolments and money rows pointing at an id that no longer exists — unreachable
 * behind `WorkspaceScope` and undeleted at the same time. And a database cascade
 * INSTANTIATES NO MODEL, so `LedgerEntry::booted()`'s append-only guard never fires
 * and the ledger loses rows silently.
 *
 * ⚠️ AND `DB_FOREIGN_KEYS` DEFAULTS TO TRUE IN THIS SUITE, so the cascade is live
 * here. That is what makes this measurable rather than a claim: a version of
 * `IdentityPersonalData` that called `->delete()` fails this file rather than
 * passing everything else.
 */
beforeEach(function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $this->workspace = $workspace;
    $this->owner = $owner;

    $this->teacher = app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): TeacherProfile => TeacherProfile::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
        ]),
    );

    $this->entry = LedgerEntry::query()->create([
        'workspace_id' => $workspace->getKey(),
        'teacher_profile_id' => $this->teacher->getKey(),
        'type' => LedgerEntryType::Bonus,
        'amount_minor' => 45_000,
        'currency' => 'QAR',
        'reason' => 'حصّة',
    ]);
});

it('erases the owner without destroying the workspace or the ledger', function (): void {
    $before = (int) LedgerEntry::query()->withoutWorkspaceScope()->sum('amount_minor');

    $request = app(CreateDataRequest::class)->handle(
        $this->owner,
        (string) $this->owner->uuid,
        DataRequestType::Erasure,
    );

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect(Workspace::query()->whereKey($this->workspace->getKey())->exists())->toBeTrue()
        ->and(LedgerEntry::query()->withoutWorkspaceScope()->whereKey($this->entry->getKey())->exists())->toBeTrue()
        ->and((int) LedgerEntry::query()->withoutWorkspaceScope()->sum('amount_minor'))->toBe($before);
});

/*
 * ⚠️ THE MONEY ROW KEEPS ITS POINTER, AND SEVERING IT WOULD SATISFY NOTHING.
 *
 * `orders.user_id` is NOT NULL and every total joins through it. The identity is
 * severed at the `users` row instead — the account it points at now names nobody —
 * so FR-021 (keep the financial record) and FR-022 (do not change its arithmetic)
 * are both satisfied by leaving it exactly where it is.
 */
it('keeps the order pointing at the anonymised account', function (): void {
    $student = User::factory()->create();

    $order = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Order => Order::create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $student->getKey(),
            'kind' => OrderKind::Course,
            'amount_minor' => 22_000,
            'currency' => 'QAR',
            'provider' => 'manual',
            'status' => 'approved',
        ]),
    );

    $request = app(CreateDataRequest::class)->handle($student, (string) $student->uuid, DataRequestType::Erasure);
    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $order->refresh();

    expect((int) $order->user_id)->toBe((int) $student->getKey())
        ->and((int) $order->amount_minor)->toBe(22_000)
        // And the account it names is nobody.
        ->and((string) User::query()->find($student->getKey())?->email)->toStartWith('anonymised+');
});
