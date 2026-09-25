<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\Gamification\Models\AwardEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every entry one CAUSE ever wrote, read as a chain — and the one place that
 * appends to it.
 *
 * A cause (student · action · source_type · source_id) can be true, then false,
 * then true again: a mark changed to absent and back, a pass revised into a fail
 * and back. The ledger is append-only, so each change is a NEW row that negates
 * the one before it:
 *
 *     original (+10)   reversal_of_id = 0
 *     reversal (−10)   reversal_of_id = original.id
 *     reinstate (+10)  reversal_of_id = reversal.id
 *     reversal (−10)   reversal_of_id = reinstate.id
 *
 * ⚠️ EACH ROW POINTS AT THE HEAD IT NEGATES, AND THAT IS WHAT KEEPS THE CHAIN
 * LINEAR. `reversal_of_id` is inside the unique key, so two runners that read the
 * same head and both try to negate it collide on the index: one row lands, the
 * other finds it and reports "already done". A reinstatement written as a second
 * `reversal_of_id = 0` row is impossible for the same reason — it would collide
 * with the original and be swallowed, which is exactly how present→absent→present
 * used to end with the points gone for ever.
 *
 * ⚠️ PARITY, NEVER THE SIGN, SAYS WHETHER THE CAUSE IS CURRENTLY PAID. An odd
 * number of rows is "held", an even number is "reversed". The sign of `xp` cannot
 * answer it: `ProgressWriter::deductible()` legitimately clamps a reversal to
 * 0/0 when the student has already spent what it would take back.
 *
 * ⚠️ AND THE NEGATION IS OF THE HEAD, NOT OF THE ORIGINAL. A reinstatement returns
 * exactly what the reversal took — if the reversal could only take 2 of 5 coins,
 * giving back 5 would pay the cause 8 in total. Negating the head keeps the sum of
 * every chain equal to either the original or zero, whatever was clamped on the
 * way. It also never consults today's catalogue or the daily cap: restoring an
 * award that was already granted once is not a new award.
 */
class AwardChain
{
    public function __construct(private readonly ProgressWriter $progress) {}

    /**
     * The first entry the cause wrote, or null if it never paid.
     */
    public function original(int $studentUserId, string $actionKey, string $sourceType, int $sourceId): ?AwardEntry
    {
        return AwardEntry::query()
            ->where('student_user_id', $studentUserId)
            ->where('action_key', $actionKey)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('reversal_of_id', 0)
            ->first();
    }

    /**
     * Append the negation of the head — but only if the chain is currently in the
     * state the caller expects to leave.
     *
     * @param  bool  $fromHeld  true to reverse (the chain must be held), false to reinstate (it must be reversed)
     * @return AwardEntry|null null when the chain is already where the caller wants it
     */
    public function negateHead(AwardEntry $original, bool $fromHeld): ?AwardEntry
    {
        if ($original->reversal_of_id !== 0) {
            // Callers name the CAUSE by its original. Handing in a later link
            // would make "which state is this chain in" a guess.
            throw new RuntimeException('لا يُعكَس قيدٌ عكسيّ.');
        }

        return DB::transaction(function () use ($original, $fromHeld): ?AwardEntry {
            $links = AwardEntry::query()
                ->where('student_user_id', $original->student_user_id)
                ->where('action_key', $original->action_key)
                ->where('source_type', $original->source_type)
                ->where('source_id', $original->source_id);

            $held = (clone $links)->count() % 2 === 1;

            if ($held !== $fromHeld) {
                return null;
            }

            /** @var AwardEntry $head */
            $head = (clone $links)->orderByDesc('id')->firstOrFail();

            /*
            | The amounts FROZEN on the head, negated — never today's catalogue. An
            | action re-priced between the award and the correction would otherwise
            | return a different number from the one that was given, and the ledger
            | would stop summing to the aggregate.
            */
            $xp = $this->progress->deductible(
                -$head->xp,
                $this->progress->progressFor($head->student_user_id)->xp,
            );

            $coins = -$head->coins;

            if ($coins < 0 && $head->workspace_id !== null) {
                $balance = $this->progress->coinBalanceFor($head->student_user_id, (int) $head->workspace_id);
                $coins = $this->progress->deductible($coins, $balance->coins);
            }

            $uuid = (string) Str::uuid();

            $inserted = AwardEntry::query()->insertOrIgnore([
                'uuid' => $uuid,
                'student_user_id' => $head->student_user_id,
                'action_key' => $head->action_key,
                'xp' => $xp,
                'coins' => $coins,
                'workspace_id' => $head->workspace_id,
                'course_id' => $head->course_id,
                'lesson_id' => $head->lesson_id,
                // Carried, not recomputed: the rebuild must reproduce the ranking
                // that was actually shown (SC-011).
                'level_band' => $head->level_band,
                'source_type' => $head->source_type,
                'source_id' => $head->source_id,
                // The fifth column of the key. Everything above repeats the head
                // exactly; this is what makes the row distinct — and what makes a
                // second runner negating the same head collide instead of doubling.
                'reversal_of_id' => $head->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                if (AwardEntry::query()->where('reversal_of_id', $head->getKey())->exists()) {
                    return null;
                }

                throw new RuntimeException(
                    'القيدُ العكسيُّ لم يُكتب ولا يوجد قيدٌ مطابق — insertOrIgnore ابتلع فشلاً حقيقياً.',
                );
            }

            if (! $this->progress->moveXp($head->student_user_id, $xp)) {
                throw new RuntimeException('تعذّر تحريك الخبرة: تغيّر الرصيد أثناء العملية.');
            }

            if ($coins !== 0 && $head->workspace_id !== null
                && ! $this->progress->moveCoins($head->student_user_id, (int) $head->workspace_id, $coins)) {
                throw new RuntimeException('تعذّر تحريك العملات: تغيّر الرصيد أثناء العملية.');
            }

            return AwardEntry::query()->where('uuid', $uuid)->firstOrFail();
        });
    }
}
