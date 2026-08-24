<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Models\Announcement;
use App\Shared\Actions\Action;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How many were told, and how many have read it (FR-046).
 *
 * ⚠️ COUNTED LIVE, NEVER STORED. A stored pair drifts the first time a
 * notification is deleted — and then reports more readers than there were
 * recipients, permanently, with nothing to notice it. Deleting an old
 * notification LOWERS «who was told», which is the honest answer: the row was the
 * evidence, and the count is a statement about rows that exist.
 *
 * ⚠️ AND BOTH NUMBERS COME OFF `(source_type, source_id, read_at)`, the index the
 * 010 migration adds. Without it the question is
 * `JSON_EXTRACT(payload, '$.announcement_uuid')` — a function around a column,
 * so no index at all, on the fastest-growing table in the product, twice per row,
 * inside a list. On the five rows a local fixture holds it is instantaneous, and
 * green.
 */
class ReadAnnouncementStats extends Action
{
    /**
     * @return array{notified: int, read: int}
     */
    public function handle(Announcement $announcement): array
    {
        return $this->forMany([$announcement])[(int) $announcement->getKey()]
            ?? ['notified' => 0, 'read' => 0];
    }

    /**
     * The bulk form, for a list.
     *
     * ⚠️ A LIST PAGE SHOWS BOTH COUNTERS PER ROW, so the single-row form called
     * inside a Resource is an N+1 by construction — the rule this repository has
     * already written down for `ClassSessionResource` and for `WithholdingReader`.
     * One grouped query for the page, stamped onto the rows before they render.
     *
     * @param  iterable<Announcement>  $announcements
     * @return array<int, array{notified: int, read: int}> keyed by announcement id
     */
    public function forMany(iterable $announcements): array
    {
        /** @var Collection<int, Announcement> $rows */
        $rows = collect($announcements);

        $ids = $rows->map(fn (Announcement $a): int => (int) $a->getKey())->all();

        if ($ids === []) {
            return [];
        }

        /*
        | ⚠️ THE QUERY BUILDER, NOT THE MODEL, AND THAT IS SAFE HERE FOR A REASON
        | WORTH STATING. `notifications` carries no global scope at all — it is a
        | bridge entity by the constitution's own classification, guarded by
        | `recipient_user_id` on every read path — so there is nothing for a model
        | query to add. What a model query WOULD add is hydration: one Eloquent
        | object per group, for two integers each.
        */
        $counts = DB::table('notifications')
            ->where('source_type', Announcement::SOURCE_TYPE)
            ->whereIn('source_id', $ids)
            ->selectRaw('source_id, COUNT(*) as notified, COUNT(read_at) as reads')
            ->groupBy('source_id')
            ->get();

        $stats = [];

        foreach ($ids as $id) {
            $stats[$id] = ['notified' => 0, 'read' => 0];
        }

        foreach ($counts as $row) {
            $stats[(int) $row->source_id] = [
                'notified' => (int) $row->notified,
                // COUNT(column) skips nulls on both engines, so this is the
                // number of rows whose `read_at` is set — no second query and no
                // `whereNotNull` pass over the same index.
                'read' => (int) $row->reads,
            ];
        }

        return $stats;
    }
}
