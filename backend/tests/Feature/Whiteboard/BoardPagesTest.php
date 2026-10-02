<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 · US3 — add, copy, reorder and delete pages. Every one starts with the
| board's row gate, so the page ceiling and the positions hold under a race.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(3)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->teacher->getKey(),
    ]);
    $this->tab = (string) Str::uuid();

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    app()->forgetScopedInstances();
    Sanctum::actingAs($this->teacher);
    $this->postJson('/api/v1/boards/'.$this->board->uuid.'/lock', ['tab' => $this->tab])->assertOk();
});

/** @return list<string> the board's page uuids in position order */
function wbOrder(): array
{
    return DB::table('board_pages')->where('board_id', test()->board->id)->orderBy('position')->pluck('uuid')->map(fn ($u) => (string) $u)->all();
}

function wbUrl(string $suffix = ''): string
{
    return '/api/v1/boards/'.test()->board->uuid.'/pages'.$suffix;
}

it('adds a blank page after the one named, and copies a page with its frame renamed', function (): void {
    [$a, $b, $c] = wbOrder();

    $added = $this->postJson(wbUrl(), ['tab' => $this->tab, 'after' => $a])->assertCreated();
    expect(wbOrder())->toBe([$a, $added->json('uuid'), $b, $c]);

    $copy = $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $c])->assertCreated();
    $scene = json_decode((string) $copy->json('scene'), true);

    expect(wbOrder())->toHaveCount(5)
        ->and(wbOrder()[4])->toBe($copy->json('uuid'))
        ->and($scene['elements'][0]['id'])->toBe('frame:'.$copy->json('uuid'))
        ->and(DB::table('boards')->where('id', $this->board->id)->value('pages_count'))->toBe(5);
});

it('reorders to exactly the order sent, with no position ever bound below zero', function (): void {
    [$a, $b, $c] = wbOrder();
    $bound = [];
    DB::beforeExecuting(function (string $sql, array $bindings) use (&$bound): void {
        if (str_contains($sql, 'board_pages SET position')) {
            $bound = [...$bound, ...array_filter($bindings, 'is_int')];
        }
    });

    $this->putJson(wbUrl('/order'), ['tab' => $this->tab, 'pages' => [$c, $a, $b]])
        ->assertOk()
        ->assertJsonPath('pages.0', ['uuid' => $c, 'position' => 1]);

    expect(wbOrder())->toBe([$c, $a, $b])
        ->and(min($bound))->toBeGreaterThanOrEqual(0);
});

it('refuses an order that is not every page of the board, each once', function (array $pages): void {
    $this->putJson(wbUrl('/order'), ['tab' => $this->tab, 'pages' => $pages])
        ->assertStatus(409)->assertJsonPath('code', 'pages_changed');
})->with([
    'one missing' => fn () => array_slice(wbOrder(), 0, 2),
    'one twice' => fn () => [wbOrder()[0], wbOrder()[0], wbOrder()[1]],
    'a stranger' => fn () => [...array_slice(wbOrder(), 0, 2), (string) Str::uuid()],
]);

it('deletes a page and closes the gap, and never the last page', function (): void {
    [$a, $b, $c] = wbOrder();

    $this->deleteJson(wbUrl('/'.$b), ['tab' => $this->tab])->assertNoContent();
    expect(wbOrder())->toBe([$a, $c])
        ->and(DB::table('board_pages')->where('board_id', $this->board->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2]);

    $this->deleteJson(wbUrl('/'.$a), ['tab' => $this->tab])->assertNoContent();
    $this->deleteJson(wbUrl('/'.$c), ['tab' => $this->tab])->assertStatus(422)->assertJsonPath('code', 'last_page');
    expect(wbOrder())->toBe([$c]);
});

it('opens every page-changing transaction with the gate', function (): void {
    $firsts = [];
    $armed = false;
    Event::listen(TransactionBeginning::class, function () use (&$armed): void {
        $armed = true;
    });
    DB::listen(function (QueryExecuted $query) use (&$armed, &$firsts): void {
        if ($armed) {
            $firsts[] = $query->sql;
            $armed = false;
        }
    });
    [$a, $b] = wbOrder();

    $this->postJson(wbUrl(), ['tab' => $this->tab])->assertCreated();
    $this->putJson(wbUrl('/order'), ['tab' => $this->tab, 'pages' => array_reverse(wbOrder())])->assertOk();
    $this->deleteJson(wbUrl('/'.$b), ['tab' => $this->tab])->assertNoContent();

    expect($firsts)->toHaveCount(3);
    foreach ($firsts as $sql) {
        expect($sql)->toStartWith('UPDATE boards SET');
    }
});

it('holds the page ceiling when another add lands in the gap', function (): void {
    PlatformSettings::set('whiteboard.max_pages_per_board', 4);

    // A rival add commits between this request's checks and its gate.
    $fired = false;
    DB::beforeExecuting(function (string $sql) use (&$fired): void {
        if (! $fired && str_starts_with($sql, 'UPDATE boards SET pages_count = pages_count + ?')) {
            $fired = true;
            DB::table('boards')->where('id', $this->board->id)->increment('pages_count');
        }
    });

    $this->postJson(wbUrl(), ['tab' => $this->tab])->assertStatus(422)->assertJsonPath('code', 'too_many_pages');
    expect(BoardPage::query()->where('board_id', $this->board->id)->count())->toBe(3);
});

it('finds every page through the board — another board\'s page is a 404', function (): void {
    $other = Board::factory()->withPages(1)->create([
        'workspace_id' => $this->workspace->getKey(), 'owner_user_id' => $this->owner->getKey(),
    ]);
    $theirs = (string) DB::table('board_pages')->where('board_id', $other->id)->value('uuid');

    $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $theirs])->assertNotFound();
    $this->postJson(wbUrl(), ['tab' => $this->tab, 'after' => $theirs])->assertNotFound();
    $this->deleteJson(wbUrl('/'.$theirs), ['tab' => $this->tab])->assertNotFound();
    expect(DB::table('board_pages')->where('board_id', $other->id)->count())->toBe(1);
});

it('changes no page without the lock', function (): void {
    $stranger = (string) Str::uuid();

    $this->postJson(wbUrl(), ['tab' => $stranger])->assertStatus(409)->assertJsonPath('code', 'lock_lost');
    $this->putJson(wbUrl('/order'), ['tab' => $stranger, 'pages' => array_reverse(wbOrder())])->assertStatus(409);
    $this->deleteJson(wbUrl('/'.wbOrder()[0]), ['tab' => $stranger])->assertStatus(409);

    expect(BoardPage::query()->where('board_id', $this->board->id)->count())->toBe(3)
        ->and(DB::table('boards')->where('id', $this->board->id)->value('pages_count'))->toBe(3);
});

it('counts a copied page against the board\'s byte ceiling', function (): void {
    PlatformSettings::set('whiteboard.max_board_bytes', 2000);
    [$first] = wbOrder();

    // Each page is a blank of a few hundred bytes; copying one pushes the board past 2000.
    $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $first])->assertCreated();
    $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $first])->assertCreated();
    $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $first])->assertCreated();

    $refused = $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $first]);
    while ($refused->status() === 201) {
        $refused = $this->postJson(wbUrl(), ['tab' => $this->tab, 'duplicate_of' => $first]);
    }
    $refused->assertStatus(422)->assertJsonPath('code', 'board_too_large');
    expect((int) DB::table('board_pages')->where('board_id', $this->board->id)->sum('scene_bytes'))->toBeLessThanOrEqual(2000);
});

it('refuses adding or deleting a page while the board is being copied, and says why', function (): void {
    DB::update('UPDATE boards SET pending_operation = ? WHERE id = ?', ['duplicating', $this->board->id]);
    [$first] = wbOrder();

    $this->postJson(wbUrl(), ['tab' => $this->tab])->assertStatus(409)->assertJsonPath('code', 'operation_pending');
    $this->deleteJson(wbUrl('/'.$first), ['tab' => $this->tab])->assertStatus(409)->assertJsonPath('code', 'operation_pending');
});
