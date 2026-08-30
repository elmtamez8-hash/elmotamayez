<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\Payments\Support\StudentBalanceAllowlist;

/**
 * The only fields a leaderboard row may carry (FR-027).
 *
 * Three of the six scopes cross workspace boundaries by design, so a platform
 * board is a list of minors visible to every student on the site. What it may say
 * about each of them is a rank, a number of points, a level and an abbreviated
 * name — and nothing else.
 *
 * ⚠️ BOTH HALVES ARE THE GUARD, not just {@see self::fields()}. An allowlist
 * claims an ABSENCE, and a test that only checks the listed keys are present
 * proves nothing about what else came along — the reasoning
 * {@see StudentBalanceAllowlist} writes out in full.
 */
class GamificationFieldAllowlist
{
    /** @return list<string> */
    public static function fields(): array
    {
        return [
            'rank',
            'display_name',
            'points',
            'level',
        ];
    }

    /**
     * Named absences. A field here is a build failure, not a review comment.
     *
     * ⚠️ `user_uuid` FIRST AND FOR A REASON OF ITS OWN. The others are ordinary
     * personal data; the uuid is the JOIN KEY. With it, an abbreviated name stops
     * being a pseudonym and becomes a stable identifier that links a student's
     * leaderboard row to every other surface that exposes the same uuid — which is
     * the whole difference between "خالد ك. is 7th" and "this specific child is
     * 7th".
     *
     * @return list<string>
     */
    public static function forbidden(): array
    {
        return [
            'user_uuid',
            'uuid',
            'user_id',
            'email',
            'phone',
            'last_name',
            'first_name',
            'workspace_uuid',
            'workspace_id',
            'grade_level_slug',
            /*
            | Spec 022 — the two new spellings of the same fact, FORBIDDEN for the
            | same reason the line above them is: a leaderboard crosses workspaces
            | and is read by strangers, and a minor's school year narrows them to
            | an age band. A year is SHARPER than a stage, not softer.
            */
            'school_year_slug',
            'school_year_name',
        ];
    }
}
