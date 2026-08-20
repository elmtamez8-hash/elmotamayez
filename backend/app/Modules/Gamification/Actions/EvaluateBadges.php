<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Events\BadgeAwarded;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Modules\Gamification\Models\StudentProgress;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Award every badge this student now qualifies for (FR-017 · FR-018).
 *
 * ⚠️ "ONCE AND NEVER TWICE" IS `unique(user_id, badge_key)`, NOT A CHECK HERE.
 * This runs from a queued job that a worker can replay, so a read-then-write
 * check would be the same race the daily cap has. The insert simply reports zero
 * rows the second time.
 *
 * ⚠️ AND A RULE CHANGED LATER NEVER WITHDRAWS A BADGE. What a student earned
 * under the old rule they earned; nothing in this class deletes.
 *
 * ⚠️ THE RULE TYPES ARE A CLOSED SET WITH ONE EVALUATOR EACH, deliberately not a
 * general expression engine. "Configurable rules" invites a language with no
 * vocabulary — unvalidatable, undocumentable, untestable — while the thresholds,
 * which is what the requirement actually asked to be tunable, stay editable from
 * the panel.
 */
class EvaluateBadges extends Action
{
    /** @return list<Badge> newly awarded */
    public function handle(User $student): array
    {
        $progress = StudentProgress::query()->where('user_id', $student->getKey())->first();

        if ($progress === null) {
            return [];
        }

        $awarded = [];

        // The catalogue is small reference data; the alternative is a query per
        // badge inside the loop below.
        $badges = Badge::query()->where('is_active', true)->get();

        $held = BadgeAward::query()
            ->where('user_id', $student->getKey())
            ->pluck('badge_key')
            ->flip();

        foreach ($badges as $badge) {
            if ($held->has($badge->key) || ! $this->qualifies($progress, $badge)) {
                continue;
            }

            if ($this->record($progress, $badge)) {
                $awarded[] = $badge;
                DB::afterCommit(fn () => event(new BadgeAwarded($student, $badge)));
            }
        }

        return $awarded;
    }

    private function qualifies(StudentProgress $progress, Badge $badge): bool
    {
        return match ($badge->rule_type) {
            BadgeRuleType::TotalXp => $progress->xp >= $badge->rule_value,

            // Current OR best: a student who reached seven days and then broke the
            // run still did the thing the badge is for. Reading `current_streak`
            // alone would make the badge unobtainable for anyone who ever slipped
            // after qualifying but before the job ran.
            BadgeRuleType::StreakDays => max($progress->current_streak, $progress->best_streak) >= $badge->rule_value,

            BadgeRuleType::LevelReached => $progress->level >= $badge->rule_value,

            BadgeRuleType::ActionCount => $badge->rule_action_key !== null
                && $this->countOf($progress, $badge->rule_action_key) >= $badge->rule_value,
        };
    }

    /**
     * How many times this student earned one action, net of reversals.
     *
     * ⚠️ REVERSALS ARE SUBTRACTED RATHER THAN IGNORED. Counting rows would let a
     * student keep a "twenty sessions attended" badge earned partly from sessions
     * a teacher later marked absent — and would count each of those sessions
     * TWICE, once for the award and once for its correction.
     *
     * Served by `index(student_user_id, action_key, created_at)`, which exists for
     * the daily cap and answers this with the same two leading equalities.
     */
    private function countOf(StudentProgress $progress, string $actionKey): int
    {
        $rows = AwardEntry::query()
            ->where('student_user_id', $progress->user_id)
            ->where('action_key', $actionKey);

        return (int) (clone $rows)->where('reversal_of_id', 0)->count()
            - (int) (clone $rows)->where('reversal_of_id', '<>', 0)->count();
    }

    private function record(StudentProgress $progress, Badge $badge): bool
    {
        return BadgeAward::query()->insertOrIgnore([
            // Explicit: insertOrIgnore boots no model, so HasUuid never fires and
            // on MySQL the NOT NULL violation is downgraded to a warning that
            // stores '' — after which every later row collides with it.
            'uuid' => (string) Str::uuid(),
            'user_id' => $progress->user_id,
            'badge_key' => $badge->key,
            'awarded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }
}
