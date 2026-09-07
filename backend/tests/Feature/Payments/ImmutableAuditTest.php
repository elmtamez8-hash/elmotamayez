<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Models\ActivityEntry;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| SC-009 — the trail records what happened and is never rewritten (FR-027).
|
| ⚠️ SPATIE ENFORCES NOTHING OF THIS. `activity_log` is an ordinary table with an
| ordinary model and `$guarded = []`; the package refuses no update and no
| delete. The guard is ours, on the row, and it is registered through
| `config/activitylog.php` — which is why the whole product's audit entries are
| covered rather than billing's alone.
|
| ⚠️ AND THE STATEMENT SHAPE IS TESTED, NOT ONLY THE MODEL. `DB::table(...)
| ->update()` retrieves no models and boots nothing, so it walks straight past a
| model guard. That is not a hole this design can close — it is the same limit
| `LedgerEntry` documents — and the test says so explicitly rather than leaving a
| reader to believe the row is unwritable by any means.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $order = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->student->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->actingAs($this->owner);
    app(ApproveOrder::class)->handle($order, $this->owner, '198.51.100.4', 'Firefox');

    $this->entry = ActivityEntry::query()->where('description', 'approved')->firstOrFail();
});

it('refuses to change what an entry says', function (): void {
    expect(fn () => $this->entry->update(['description' => 'rejected']))
        ->toThrow(RuntimeException::class);

    expect($this->entry->fresh()?->description)->toBe('approved');
});

it('refuses to delete an entry', function (): void {
    expect(fn () => $this->entry->delete())->toThrow(RuntimeException::class);

    // The row itself, not a global count: approving also enrols, and other
    // modules write their own entries to the same table.
    expect(ActivityEntry::query()->whereKey($this->entry->getKey())->exists())->toBeTrue();
});

it('does not pretend to stop a bulk statement, and says exactly where the line is', function (): void {
    /*
    | ⚠️ THE HONEST BOUNDARY, ASSERTED RATHER THAN COMMENTED.
    |
    | Both of these retrieve no models and boot nothing, so no model event fires:
    | Eloquent's own `query()->update()` compiles to one statement, and the query
    | builder never had a model to begin with. The guard covers the door callers
    | actually use — `$entry->update()`, `$entry->delete()` — and nothing else.
    |
    | Written as a passing test because the alternative is a reader assuming the
    | row is unwritable by any means, which is how a "tamper-proof" claim ends up
    | in a document shown to an auditor. Reaching past the application is answered
    | by the database's own permissions, not by PHP.
    */
    ActivityEntry::query()->whereKey($this->entry->getKey())->update(['description' => 'tampered by eloquent']);

    expect(ActivityEntry::query()->whereKey($this->entry->getKey())->first()?->description)
        ->toBe('tampered by eloquent');

    DB::table('activity_log')->where('id', $this->entry->getKey())->update(['description' => 'tampered by builder']);

    expect(DB::table('activity_log')->where('id', $this->entry->getKey())->value('description'))
        ->toBe('tampered by builder');
});

it('names the third door too: the package model the guard is not on', function (): void {
    /*
    | ⚠️ THE GUARD IS ON THE SUBCLASS, so a row loaded through spatie's OWN model
    | never boots it. This is not theoretical — `Spatie\Activitylog\Models    | Activity` is imported in this codebase today, by `SettlementAuditController`,
    | which happens only to read.
    |
    | Listed here because the test above enumerates the ways past the guard, and
    | an enumeration a reader takes as complete had better be. The answer is the
    | configured model: `activity_model` is what every WRITE goes through, so
    | nothing this application creates escapes it — what escapes is a reader who
    | reaches for the base class by hand.
    */
    $viaBaseModel = Activity::query()->findOrFail($this->entry->getKey());

    $viaBaseModel->delete();

    expect(ActivityEntry::query()->whereKey($this->entry->getKey())->exists())->toBeFalse();
});

it('keeps the guard on every module, not on billing alone', function (): void {
    // The model is registered as `activity_model`, so the settlement audit and
    // every other `activity()` call in the product write through the same class.
    // A guard that only covered the entries this phase writes would be a guard
    // with an exception nobody could see from the outside.
    $entry = ActivityEntry::query()->create([
        'log_name' => 'default',
        'description' => 'settlement.payout.recorded',
    ]);

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});
