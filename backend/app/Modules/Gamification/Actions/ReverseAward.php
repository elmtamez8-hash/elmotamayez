<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Undo an award whose cause turned out to be false (FR-010).
 *
 * A NEW, NEGATIVE ENTRY pointing at the original — never an update, never a
 * delete. `AwardEntry::booted()` refuses both, the same shape as Settlement's
 * ledger: the history of what a student was told they earned is part of the
 * record, and a correction that erases it leaves the student's screen and the
 * teacher's memory permanently disagreeing.
 *
 * ⚠️ THE REVERSAL CARRIES `reversal_of_id`, AND THAT COLUMN IS INSIDE THE
 * IDEMPOTENCY KEY. This is the whole reason the mechanism works at all. The
 * reversal repeats the original's student, action, source_type and source_id — so
 * without a fifth column it would collide with the entry it is reversing,
 * insertOrIgnore would write zero rows, the read-back would find the ORIGINAL,
 * the code would conclude "already recorded" and report success — and the points
 * would never be returned. To anyone watching, FR-010 would be implemented.
 *
 * ⚠️ AND THE COLUMN IS `NOT NULL DEFAULT 0`, NOT NULLABLE. `NULL != NULL` in a
 * unique index on both engines, so a nullable discriminator would stop the guard
 * biting for every ORDINARY award instead — the same event paying twice. Zero
 * compares; the sentinel is the fix. (Precedent: `concept_stats.lesson_id`.)
 */
class ReverseAward extends Action
{
    public function __construct(private readonly ProgressWriter $progress) {}

    /**
     * @return AwardEntry|null null when it was already reversed
     */
    public function handle(AwardEntry $original): ?AwardEntry
    {
        if ($original->reversal_of_id !== 0) {
            // Reversing a reversal is not a correction, it is a re-award. Nothing
            // in this phase asks for one, and allowing it silently would make the
            // ledger's sign meaningless.
            throw new RuntimeException('لا يُعكَس قيدٌ عكسيّ.');
        }

        return DB::transaction(function () use ($original): ?AwardEntry {
            /*
            | The amounts are the ones FROZEN on the original, negated — never
            | today's catalogue values. An action re-priced between the award and
            | the correction would otherwise return a different number from the one
            | that was given, and the ledger would stop summing to the aggregate.
            */
            $xp = -$original->xp;
            $coins = -$original->coins;

            $progress = $this->progress->progressFor($original->student_user_id);
            $xp = $this->progress->deductible($xp, $progress->xp);

            if ($coins < 0 && $original->workspace_id !== null) {
                $balance = $this->progress->coinBalanceFor($original->student_user_id, (int) $original->workspace_id);
                $coins = $this->progress->deductible($coins, $balance->coins);
            }

            $uuid = (string) Str::uuid();

            $inserted = AwardEntry::query()->insertOrIgnore([
                'uuid' => $uuid,
                'student_user_id' => $original->student_user_id,
                'action_key' => $original->action_key,
                'xp' => $xp,
                'coins' => $coins,
                'workspace_id' => $original->workspace_id,
                'course_id' => $original->course_id,
                'lesson_id' => $original->lesson_id,
                // Carried, not recomputed: the rebuild must reproduce the ranking
                // that was actually shown (SC-011).
                'level_band' => $original->level_band,
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                // The fifth column of the key. Everything above repeats the
                // original exactly; this is what makes the row distinct.
                'reversal_of_id' => $original->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                $existing = AwardEntry::query()->where('reversal_of_id', $original->getKey())->first();

                if ($existing !== null) {
                    return null;
                }

                throw new RuntimeException(
                    'القيدُ العكسيُّ لم يُكتب ولا يوجد قيدٌ مطابق — insertOrIgnore ابتلع فشلاً حقيقياً.',
                );
            }

            if (! $this->progress->moveXp($original->student_user_id, $xp)) {
                throw new RuntimeException('تعذّر ردُّ الخبرة: تغيّر الرصيد أثناء العملية.');
            }

            if ($coins !== 0 && $original->workspace_id !== null
                && ! $this->progress->moveCoins($original->student_user_id, (int) $original->workspace_id, $coins)) {
                throw new RuntimeException('تعذّر ردُّ العملات: تغيّر الرصيد أثناء العملية.');
            }

            return AwardEntry::query()->where('uuid', $uuid)->firstOrFail();
        });
    }
}
