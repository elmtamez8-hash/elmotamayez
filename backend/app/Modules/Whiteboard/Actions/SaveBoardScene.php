<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Data\SceneData;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\SceneValidator;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One autosave of one page (US2, R-08/R-09).
 *
 * ⚠️ THE VERSION AND THE LOCK ARE ONE STATEMENT. Read-then-write would let the old
 * holder of a lock save in the instant after the owning teacher took it over, and
 * the save after that would overwrite it silently. The UPDATE's WHERE clause is
 * the whole rule: this page at the version the tab edited from, AND the board's
 * lock held by this user in this tab with no handover past its grace.
 *
 * ⚠️ PHP CLOCK, BOUND AS A STRING; POSITIONAL `?` ONLY — see `BoardLock`.
 *
 * ⚠️ A RETRY IS NOT A CONFLICT. A tab whose save succeeded but whose answer was
 * lost sends the same `(tab, client_rev)` again; that is answered with the stored
 * version (the page moved exactly one step, from the version it sent). The same
 * `client_rev` from ANOTHER tab is not a retry — revs are per tab.
 *
 * On a conflict the server never picks a winner: it answers `version_conflict`
 * with its own copy, and the teacher chooses (ConflictDialog).
 */
final class SaveBoardScene extends Action
{
    public function __construct(private readonly SceneValidator $validator) {}

    /** @return array{version: int, client_rev: int} */
    public function handle(Board $board, BoardPage $page, User $actor, SceneData $data): array
    {
        $bytes = strlen($data->scene);

        if ($bytes > WhiteboardSettings::maxSceneBytes()) {
            throw new WhiteboardRefusal('scene_too_large');
        }

        $this->validator->validate($board, $data->scene);

        $others = (int) BoardPage::query()
            ->withoutWorkspaceScope()
            ->where('board_id', $board->getKey())
            ->where('id', '<>', $page->getKey())
            ->sum('scene_bytes');

        if ($others + $bytes > WhiteboardSettings::maxBoardBytes()) {
            throw new WhiteboardRefusal('board_too_large');
        }

        $now = CarbonImmutable::now();
        $graceEnds = BoardLock::stamp($now->subSeconds(BoardLock::HANDOVER_GRACE_SECONDS));

        $saved = DB::update(
            'UPDATE board_pages SET scene = ?, scene_bytes = ?, version = version + 1, client_tab = ?, client_rev = ?, updated_at = ? '
            .'WHERE id = ? AND version = ? AND EXISTS ('
            .'SELECT 1 FROM boards WHERE boards.id = ? AND boards.editor_user_id = ? AND boards.editor_tab_id = ? '
            .'AND (boards.editor_handover_at IS NULL OR boards.editor_handover_at > ?))',
            [
                $data->scene, $bytes, $data->tab, $data->clientRev, $now->format('Y-m-d H:i:s'),
                $page->getKey(), $data->version,
                $board->getKey(), $actor->getKey(), $data->tab, $graceEnds,
            ],
        );

        if ($saved === 1) {
            DB::table('boards')->where('id', $board->getKey())->update(['updated_at' => $now->format('Y-m-d H:i:s')]);

            return ['version' => $data->version + 1, 'client_rev' => $data->clientRev];
        }

        // Zero rows: work out which of the three it was, from what is stored NOW.
        $stored = DB::table('board_pages')->where('id', $page->getKey())->first(['version', 'scene', 'client_tab', 'client_rev']);

        if ($stored !== null
            && $stored->client_tab === $data->tab
            && (int) $stored->client_rev === $data->clientRev
            && (int) $stored->version === $data->version + 1) {
            return ['version' => (int) $stored->version, 'client_rev' => $data->clientRev];
        }

        $holds = DB::table('boards')
            ->where('id', $board->getKey())
            ->where('editor_user_id', $actor->getKey())
            ->where('editor_tab_id', $data->tab)
            ->where(fn ($q) => $q->whereNull('editor_handover_at')->orWhere('editor_handover_at', '>', $graceEnds))
            ->exists();

        if (! $holds) {
            throw new WhiteboardRefusal('lock_lost');
        }

        throw new WhiteboardRefusal('version_conflict', [
            'version' => (int) ($stored->version ?? 0),
            'scene' => (string) ($stored->scene ?? ''),
        ]);
    }
}
