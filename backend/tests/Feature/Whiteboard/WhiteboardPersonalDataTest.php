<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\WhiteboardPersonalData;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 — what the whiteboard holds about a teacher, and what erasing them does.
| The drawings are the academy's material: erasure re-points authorship to the
| workspace owner and keeps every page.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->board = Board::factory()->withPages(2)->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_user_id' => $this->teacher->getKey(),
        'title' => 'مراجعة الكيمياء',
    ]);
});

it('exports the boards a teacher authored, by title', function (): void {
    $rows = [];
    foreach ((new WhiteboardPersonalData)->export(new DataSubject(user: $this->teacher)) as $category => $chunk) {
        $rows[$category] = [...($rows[$category] ?? []), ...$chunk];
    }

    expect($rows)->toHaveKey('whiteboard_board')
        ->and(array_column($rows['whiteboard_board'], 'title'))->toBe(['مراجعة الكيمياء']);
});

it('re-points authorship to the workspace owner on erasure and keeps every page', function (): void {
    DB::table('boards')->where('id', $this->board->id)->update(['editor_user_id' => $this->teacher->getKey()]);

    $processed = (new WhiteboardPersonalData)->erase(new DataSubject(user: $this->teacher), ErasureMode::Anonymise, 100);

    $row = DB::table('boards')->where('id', $this->board->id)->first();
    expect($processed)->toBe(1)
        ->and($row->owner_user_id)->toBe($this->owner->getKey())
        ->and($row->editor_user_id)->toBeNull()
        ->and(DB::table('board_pages')->where('board_id', $this->board->id)->count())->toBe(2);
});

it('leaves everything when the mode is Retain', function (): void {
    expect((new WhiteboardPersonalData)->erase(new DataSubject(user: $this->teacher), ErasureMode::Retain, 100))->toBe(0)
        ->and(DB::table('boards')->where('id', $this->board->id)->value('owner_user_id'))->toBe($this->teacher->getKey());
});
