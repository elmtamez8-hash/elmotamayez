<?php

declare(strict_types=1);

namespace App\Modules\Community\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A teacher endorsed an answer (`FR-023`).
 *
 * ⚠️ IT CARRIES `source_type` AND `source_id`, AND THOSE TWO ARE THE IDEMPOTENCY
 * KEY. `award_entries` is unique on `(student, action, source_type, source_id,
 * reversal_of)`, so an event without them awards the points afresh on every
 * press — the unique index would collapse two different endorsements of two
 * different messages into one, or none.
 *
 * ⚠️ AND IT IS FIRED BY THE WINNER OF THE CONDITIONAL UPDATE ONLY. `WHERE
 * is_helpful = 0` reports one affected row the first time and zero the second;
 * firing on both presses and leaning on the award key would be two layers for a
 * job that has one correct owner — and the second layer is only there because the
 * first can be got wrong.
 *
 * Plain — no `ShouldBroadcast`. The mark is read from the row on the next fetch,
 * and a socket frame for a badge is a channel to keep alive for nothing.
 */
class HelpfulAnswerMarked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $studentUserId,
        public readonly int $workspaceId,
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {}
}
