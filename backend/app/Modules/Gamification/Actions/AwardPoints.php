<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Events\LevelReachedUp;
use App\Modules\Gamification\Jobs\EvaluateBadgesJob;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\Level;
use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\LevelBand;
use App\Modules\Gamification\Support\LevelLadder;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ONE way a point is ever awarded (NFR-002).
 *
 * ⚠️ THE ORDER OF THE FIVE STEPS BELOW IS NOT INTERCHANGEABLE. Read it before
 * editing anything here:
 *
 *   1. read the action — a disabled or unknown one awards nothing (FR-007)
 *   2. CLAIM the daily allowance, atomically (FR-006)
 *   3. clamp the amount to what can actually be applied (FR-009)
 *   4. write the ledger entry, idempotently (FR-004 · FR-008)
 *   5. only then move the aggregates (FR-005)
 *
 * ⚠️ STEP 4 BEFORE STEP 5, ALWAYS. Reversed, a redelivered event moves the
 * aggregate twice while the duplicate entry is ignored once — and the balance
 * stops equalling the sum of its entries permanently, with nothing to notice it.
 * The reconciliation job is what would notice, the following night, by which time
 * the cause is a week of traffic away. Precedent, in the same words:
 * `CreditLedger::post()`.
 *
 * Returns null when nothing was awarded — an unknown or disabled action, the cap
 * already reached, or the same event arriving twice. **None of those is a failure
 * of the operation that triggered it** (FR-006): a student who has hit their cap
 * still attends the session.
 */
class AwardPoints extends Action
{
    public function __construct(
        private readonly GamificationCalendar $calendar,
        private readonly LevelBand $bands,
        private readonly ProgressWriter $progress,
        private readonly RecalculateStreak $streaks,
        private readonly LevelLadder $levels,
    ) {}

    public function handle(AwardRequest $request): ?AwardEntry
    {
        $action = GamificationAction::query()->where('key', $request->actionKey)->first();

        // No row is not an error: it is a catalogue nobody has filled in yet.
        if ($action === null || ! $action->is_active) {
            return null;
        }

        /*
        | ⚠️ COINS WITH NO WORKSPACE HAVE NOWHERE CORRECT TO GO. A purse belongs to
        | one teacher (FR-028ج), so an action worth coins that fires outside any
        | workspace — a focus session — has no valid destination. Refusing here is
        | what stops the alternative: routing them by WorkspaceContext, which
        | resolves to `last_workspace_id` for a student and would quietly let them
        | choose which teacher's shop to enrich.
        */
        if ($action->coins !== 0 && $request->workspaceId === null) {
            throw new RuntimeException(
                "فعلٌ يمنح عملاتٍ بلا مساحةِ عمل: [{$request->actionKey}]. العملاتُ مقسَّمةٌ بالسياق ولا وجهةَ صحيحةَ لها.",
            );
        }

        return DB::transaction(function () use ($request, $action): ?AwardEntry {
            if (! $this->claimDailyAllowance($request, $action)) {
                return null;
            }

            $progress = $this->progress->progressFor($request->studentUserId);

            $xp = $this->progress->deductible($action->xp, $progress->xp);

            $coins = 0;

            if ($action->coins !== 0 && $request->workspaceId !== null) {
                $balance = $this->progress->coinBalanceFor($request->studentUserId, $request->workspaceId);
                $coins = $this->progress->deductible($action->coins, $balance->coins);
            }

            $entry = $this->writeEntry($request, $xp, $coins, $this->bands->for($progress->level));

            if ($entry === null) {
                return null;
            }

            $this->applyAggregates($request, $xp, $coins);

            $this->streaks->handle($progress->refresh());
            $this->settleLevel($progress->refresh());

            /*
            | ⚠️ QUEUED, AND AFTER COMMIT. Badge rules count rows in the ledger, so
            | evaluating them inline would put a second set of queries on the path
            | of every award — the thing SC-016 measures. And dispatching before
            | the commit lands means the job can read a ledger that does not yet
            | contain the entry that triggered it.
            */
            DB::afterCommit(fn () => EvaluateBadgesJob::dispatch((int) $request->studentUserId));

            return $entry;
        });
    }

    /**
     * Raise the level, and congratulate at most once per level.
     *
     * ⚠️ TWO CONDITIONAL STATEMENTS, NOT ONE. Experience can go DOWN — a penalty
     * is an ordinary catalogue row — so a level written unconditionally would drop
     * a student and "promote" them again on their next award, firing the event and
     * a congratulation each time. The ratchet is `WHERE level < :n`; the
     * announcement is a separate claim on `notified_level`, because the award path
     * can run twice for one event and the second congratulation is the one the
     * student notices.
     */
    private function settleLevel(StudentProgress $progress): void
    {
        $level = $this->levels->levelFor($progress->xp);

        $this->levels->raise((int) $progress->user_id, $level);

        if (! $this->levels->claimCongratulation((int) $progress->user_id, $level)) {
            return;
        }

        $name = Level::query()->where('level', $level)->value('name');
        $student = $progress->user;

        if ($student === null) {
            return;
        }

        DB::afterCommit(fn () => event(new LevelReachedUp($student, $level, (string) $name)));
    }

    /**
     * Take one unit of today's allowance, or report that it is spent.
     *
     * ⚠️ ONE CONDITIONAL STATEMENT, and counting today's entries first is exactly
     * the race this replaces: two events in the same second both read 9 of 10 and
     * both write, landing at 11. Zero affected rows IS "the cap is reached".
     *
     * The seat idiom, and never `lockForUpdate()` — a no-op on SQLite.
     */
    private function claimDailyAllowance(AwardRequest $request, GamificationAction $action): bool
    {
        if ($action->daily_cap === null) {
            return true;
        }

        $dayKey = $this->calendar->dayKey();

        DB::table('award_daily_counters')->insertOrIgnore([
            // Explicit, for the reason written in ProgressWriter: no model is
            // booted by a Query Builder insert, so HasUuid never fires.
            'uuid' => (string) Str::uuid(),
            'student_user_id' => $request->studentUserId,
            'day_key' => $dayKey,
            'action_key' => $request->actionKey,
            'count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('award_daily_counters')
            ->where('student_user_id', $request->studentUserId)
            ->where('day_key', $dayKey)
            ->where('action_key', $request->actionKey)
            ->where('count', '<', $action->daily_cap)
            ->update(['count' => DB::raw('count + 1'), 'updated_at' => now()]) > 0;
    }

    /**
     * Step 4 — insertOrIgnore, then read back, and THROW if neither.
     *
     * ⚠️ ZERO ROWS MEANS ONE OF TWO THINGS: a genuine duplicate, or a real failure
     * (a null, a foreign key, an out-of-range value) that insertOrIgnore flattened
     * into "nothing happened". Returning null for both would make the idempotency
     * test pass against an implementation that writes nothing at all. So the row
     * is fetched by its key and a miss throws.
     */
    private function writeEntry(AwardRequest $request, int $xp, int $coins, int $band): ?AwardEntry
    {
        $uuid = (string) Str::uuid();

        $inserted = AwardEntry::query()->insertOrIgnore([
            'uuid' => $uuid,
            'student_user_id' => $request->studentUserId,
            'action_key' => $request->actionKey,
            'xp' => $xp,
            'coins' => $coins,
            'workspace_id' => $request->workspaceId,
            'course_id' => $request->courseId,
            'lesson_id' => $request->lessonId,
            'level_band' => $band,
            'source_type' => $request->sourceType,
            'source_id' => $request->sourceId,
            // Zero, never null: NULL never equals NULL, so a nullable column
            // inside the unique key would stop it biting for every ordinary award.
            'reversal_of_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            return AwardEntry::query()->where('uuid', $uuid)->firstOrFail();
        }

        $existing = AwardEntry::query()
            ->where('student_user_id', $request->studentUserId)
            ->where('action_key', $request->actionKey)
            ->where('source_type', $request->sourceType)
            ->where('source_id', $request->sourceId)
            ->where('reversal_of_id', 0)
            ->first();

        if ($existing !== null) {
            return null;
        }

        throw new RuntimeException(
            'قيدُ المنح لم يُكتب ولا يوجد قيدٌ مطابق — insertOrIgnore ابتلع فشلاً حقيقياً.',
        );
    }

    /**
     * Step 5 — the aggregates, each by a conditional statement.
     *
     * A deduction that cannot be applied in full throws, which rolls the whole
     * transaction back: the entry, the counter claim and any partial move. The
     * alternative — leaving the entry and floor the aggregate — is precisely the
     * permanent FR-005 breach the clamp exists to prevent.
     */
    private function applyAggregates(AwardRequest $request, int $xp, int $coins): void
    {
        if (! $this->progress->moveXp($request->studentUserId, $xp)) {
            throw new RuntimeException('تعذّر تحريك الخبرة: تغيّر الرصيد أثناء العملية.');
        }

        if ($coins !== 0 && $request->workspaceId !== null
            && ! $this->progress->moveCoins($request->studentUserId, $request->workspaceId, $coins)) {
            throw new RuntimeException('تعذّر تحريك العملات: تغيّر الرصيد أثناء العملية.');
        }
    }
}
