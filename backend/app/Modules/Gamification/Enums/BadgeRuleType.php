<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Enums;

/**
 * How a badge is earned.
 *
 * ⚠️ A CLOSED SET WITH AN EVALUATOR PER MEMBER, deliberately not a general rule
 * engine. "Configurable badge rules" (FR-017) invites an expression language with
 * no vocabulary, which is a feature nobody can validate, document or test — and
 * this repository's convention is that every closed set is an enum
 * (LessonTypeRegistry is the same answer to the same question in spec 016).
 * Adding a rule means adding a case here and a branch in EvaluateBadges; the
 * thresholds stay editable from the panel, which is what the requirement asked
 * for.
 */
enum BadgeRuleType: string
{
    /** N entries for one action_key, ever. `rule_action_key` names the action. */
    case ActionCount = 'action_count';

    /** N experience points in total. */
    case TotalXp = 'total_xp';

    /** A streak of N consecutive days — current or best. */
    case StreakDays = 'streak_days';

    /** Reaching level N. */
    case LevelReached = 'level_reached';

    /** Whether the rule needs `rule_action_key` to mean anything. */
    public function needsActionKey(): bool
    {
        return $this === self::ActionCount;
    }
}
