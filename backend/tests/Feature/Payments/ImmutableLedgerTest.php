<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;

/*
| SC-002 — a ledger entry cannot be amended or removed.
|
| The guard sits on the MODEL, not in the Action, so the route taken to reach it
| does not matter: Filament, a console command and a test all hit the same
| `updating` hook. A rule enforced only in the Action is a rule with a door beside
| it.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $balance = billingBalance($this->workspace, User::factory()->create());
    grantCredits($balance, 8, 'immutable');

    $this->entry = CreditTransaction::query()->withoutWorkspaceScope()->firstOrFail();
});

it('refuses to update an entry', function (): void {
    expect(fn () => $this->entry->update(['credits' => 99]))->toThrow(RuntimeException::class);

    expect(CreditTransaction::query()->withoutWorkspaceScope()->firstOrFail()->credits)->toBe(8);
});

it('refuses to delete an entry', function (): void {
    expect(fn () => $this->entry->delete())->toThrow(RuntimeException::class);

    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1);
});

/*
| The hole, documented rather than discovered.
|
| A mass update retrieves no models, so no `updating` event fires and the guard
| is bypassed entirely. This is not a defect in the guard — an Eloquent model
| cannot see a statement that never instantiates it — it is the reason the rule
| also has to be a review rule. Spec 014's LedgerEntry carries the same hole and
| the same note, and its one sanctioned post-insert write (period stamping) is
| the only mass update in that module.
|
| Asserted rather than left implicit, so that anyone who "fixes" this test by
| making the mass path throw finds out that they cannot.
*/
it('documents that a mass update bypasses the model guard', function (): void {
    DB::table('credit_transactions')->where('id', $this->entry->getKey())->update(['credits' => 99]);

    expect((int) DB::table('credit_transactions')->where('id', $this->entry->getKey())->value('credits'))
        ->toBe(99);
});
