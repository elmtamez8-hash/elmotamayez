<?php

declare(strict_types=1);

/*
 * Fallbacks only — both are rows in `platform_settings` an operator edits.
 *
 * See `PlatformSettings::KEYS` for why exactly one of these is read at runtime.
 */

return [
    /*
    | What an invitation is worth, in xp.
    |
    | ⚠️ READ ONCE, BY THE BACKFILL MIGRATION, to seed the `invite_friend`
    | catalogue row. The catalogue is authoritative afterwards: `AwardPoints`
    | reads the row and nothing reads this key again, because two live sources
    | for one number is the drift this repository has paid for repeatedly.
    */
    'reward_points' => 50,

    /*
    | How many completed referrals one person may be paid for (FR-023).
    |
    | THIS one is read on every completion. A cap is the whole of the abuse
    | defence that self-referral detection cannot cover: one person with a
    | hundred throwaway email addresses passes every equality check and is
    | stopped only by a number.
    */
    'max_completed_per_referrer' => 20,
];
