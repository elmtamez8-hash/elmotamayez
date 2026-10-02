<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · R-09 — the edit lock: one editor, a 5-second heartbeat, expiry after
| 120 seconds of silence, and the owning teacher's «خُذ التحرير» with a 10-second
| grace (Q3, approved 2026-10-01).
|
| The clock is PHP's (bound as strings), so `travel()` moves it — the reason the
| design binds time instead of asking the database for NOW().
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Course-less and created by the owner, so the owner is its owning teacher.
    $this->board = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_user_id' => $this->owner->getKey(),
    ]);
    $this->lock = new BoardLock;
    $this->ownerTab = (string) Str::uuid();
    $this->assistantTab = (string) Str::uuid();
});

function wbLockRow(Board $board): object
{
    return DB::table('boards')->where('id', $board->id)->first();
}

it('gives a free lock to the first tab, refuses a second, and renews the holder', function (): void {
    expect($this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab)['held'])->toBeTrue()
        ->and($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeFalse();

    $this->travel(5)->seconds();

    expect($this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab)['held'])->toBeTrue()
        ->and(wbLockRow($this->board)->editor_tab_id)->toBe($this->assistantTab);
});

it('frees a lock after 120 seconds without a heartbeat — a closed or disconnected tab', function (): void {
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);

    $this->travel(119)->seconds();
    expect($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeFalse();

    $this->travel(2)->seconds();
    expect($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeTrue();
});

it('hands the lock to the owning teacher after a 10-second grace, and the old tab loses it', function (): void {
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);

    $moves = $this->lock->take($this->board, $this->ownerTab);
    expect($moves)->not->toBeNull();

    // Asked again, the same moment — a second click does not restart the grace.
    $this->travel(3)->seconds();
    expect($this->lock->take($this->board, $this->ownerTab))->toBe($moves);

    // Inside the grace the holder still holds, and is told to save and let go.
    expect($this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab))
        ->toBe(['held' => true, 'handover_requested' => true])
        ->and($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeFalse();

    $this->travel(8)->seconds();

    expect($this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab)['held'])->toBeFalse()
        ->and($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeTrue()
        ->and(wbLockRow($this->board)->editor_user_id)->toBe($this->owner->id)
        ->and(wbLockRow($this->board)->editor_handover_tab)->toBeNull();

    try {
        $this->lock->assertHeldBy($this->board, $this->assistant->id, $this->assistantTab);
        $this->fail('The old tab still held the lock.');
    } catch (WhiteboardRefusal $refusal) {
        expect($refusal->reason)->toBe('lock_lost')
            ->and($refusal->status())->toBe(409);
    }
});

it('passes the lock straight to the waiting tab when the holder releases during a handover', function (): void {
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);
    $this->lock->take($this->board, $this->ownerTab);

    $this->lock->release($this->board, $this->assistant->id, $this->assistantTab);

    $row = wbLockRow($this->board);
    expect($row->editor_tab_id)->toBe($this->ownerTab)
        ->and($row->editor_user_id)->toBe($this->owner->id)
        ->and($row->editor_handover_tab)->toBeNull()
        ->and($row->editor_seen_at)->not->toBeNull();
});

it('frees the lock entirely on a release with nobody waiting', function (): void {
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);
    $this->lock->release($this->board, $this->assistant->id, $this->assistantTab);

    $row = wbLockRow($this->board);
    expect($row->editor_tab_id)->toBeNull()
        ->and($row->editor_user_id)->toBeNull()
        ->and($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeTrue();
});

it('counts a renewal that changed nothing as held — the MySQL zero-rows case', function (): void {
    CarbonImmutable::setTestNow('2026-10-02 10:00:00.250');
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);

    // The same instant again: the UPDATE writes the value already stored.
    expect($this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab)['held'])->toBeTrue();
});

it('binds every lock time as a string with milliseconds, into millisecond columns', function (): void {
    $migration = (string) file_get_contents(app_path('Modules/Whiteboard/Database/Migrations/2026_10_02_000100_create_whiteboard_tables.php'));
    expect($migration)->toContain("timestamp('editor_seen_at', 3)")
        ->and($migration)->toContain("timestamp('editor_handover_at', 3)");

    $bound = [];
    DB::beforeExecuting(function (string $sql, array $bindings) use (&$bound): void {
        if (str_contains($sql, 'editor_seen_at')) {
            $bound = [...$bound, ...$bindings];
        }
    });

    CarbonImmutable::setTestNow('2026-10-02 10:00:00.250');
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);

    $times = array_values(array_filter($bound, fn ($value): bool => is_string($value) && str_starts_with($value, '2026-10-02')));
    expect($times)->not->toBeEmpty();
    foreach ($times as $time) {
        expect($time)->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/');
    }
});

/*
| ⚠️ THE RACE, WRITTEN INTO THE GAP. A sequential test of an atomic claim is green
| against a build with no claim in it (gotchas/testing.md). Here the competing write
| — a handover whose grace has already run out — lands between the holder deciding
| to renew and its UPDATE executing. A read-then-write renewal would renew anyway;
| the conditional UPDATE refuses.
*/
it('refuses a renewal whose handover ran out between the decision and the write', function (): void {
    $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);
    $this->travel(3)->seconds();

    $competed = false;
    DB::beforeExecuting(function (string $sql) use (&$competed): void {
        if ($competed || ! str_starts_with($sql, 'update "boards" set "editor_seen_at"')) {
            return;
        }
        $competed = true;
        DB::table('boards')->where('id', $this->board->id)->update([
            'editor_handover_tab' => $this->ownerTab,
            'editor_handover_at' => BoardLock::stamp(CarbonImmutable::now()->subSeconds(11)),
        ]);
    });

    $result = $this->lock->acquire($this->board, $this->assistant->id, $this->assistantTab);

    expect($competed)->toBeTrue()
        ->and($result['held'])->toBeFalse()
        ->and($this->lock->acquire($this->board, $this->owner->id, $this->ownerTab)['held'])->toBeTrue();
});
