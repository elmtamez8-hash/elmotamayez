<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the whiteboard holds about a person: that they authored a board, or hold
 * its edit lock. Never the drawings themselves — a
 * board is the WORKSPACE's teaching material (constitution I), the way a course is.
 *
 * ⚠️ ERASURE RE-POINTS, IT DOES NOT DELETE. Teacher offboarding (FR-037) keeps a
 * departed teacher's material with the academy, so erasing the person moves
 * `owner_user_id` to the workspace's owner and clears
 * the edit lock. `owner_user_id` is NOT NULL, so «anonymise to null» is not
 * available; when the subject IS the workspace owner the rows are left for the
 * workspace's own offboarding, which already decides that case.
 */
class WhiteboardPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'whiteboard';
    }

    public function describe(): array
    {
        return ['whiteboard_board'];
    }

    public function export(DataSubject $subject): iterable
    {
        yield from ExportWalk::keyed(
            'whiteboard_board',
            Board::query()
                ->withoutWorkspaceScope()
                ->where('owner_user_id', $subject->user->getKey())
                ->select(['id', 'uuid', 'title', 'pages_count', 'created_at', 'updated_at']),
            fn (Board $board): array => [
                'uuid' => $board->uuid,
                'title' => $board->title,
                'pages' => $board->pages_count,
                'created_at' => ExportWalk::at($board->created_at),
                'updated_at' => ExportWalk::at($board->updated_at),
            ],
            column: 'id',
        );
    }

    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Anonymise) {
            return 0;
        }

        $userId = (int) $subject->user->getKey();

        // The edit lock names a person only while they hold it — clear it outright.
        DB::table('boards')->where('editor_user_id', $userId)->update([
            'editor_user_id' => null,
            'editor_tab_id' => null,
            'editor_seen_at' => null,
        ]);

        $processed = 0;

        foreach ([Board::class => 'owner_user_id'] as $model => $column) {
            $model::query()
                ->withoutWorkspaceScope()
                ->where($column, $userId)
                ->chunkById(200, function ($rows) use ($column, $userId, $limit, &$processed): bool {
                    $owners = Workspace::query()
                        ->whereIn('id', $rows->pluck('workspace_id')->unique())
                        ->pluck('owner_user_id', 'id');

                    foreach ($rows as $row) {
                        if ($processed >= $limit) {
                            return false;
                        }

                        $owner = $owners[$row->workspace_id] ?? null;
                        if ($owner === null || (int) $owner === $userId) {
                            continue;
                        }

                        $row->forceFill([$column => $owner])->saveQuietly();
                        $processed++;
                    }

                    return true;
                });
        }

        return $processed;
    }

    /** Teaching material has no retention sweep: it lives as long as the workspace keeps it. */
    public function expire(
        string $category,
        CarbonImmutable $before,
        ExpiryBehaviour $mode,
        int $limit,
        array $exemptUserIds = [],
    ): int {
        return 0;
    }
}
