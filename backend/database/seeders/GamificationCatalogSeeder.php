<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Filament\Resources\GamificationActionResource;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Gamification\Models\Level;
use Illuminate\Database\Seeder;

/**
 * ⚠️ REFERENCE DATA, NOT FIXTURES — the same status as NotificationTemplateSeeder.
 *
 * `AwardPoints` looks an action up by key and returns silently when there is no
 * row: an award for an action nobody defined is not an error, it is a catalogue
 * that has not been filled in. So without these rows NOTHING is ever awarded, and
 * every assertion in the suite about points, levels, streaks and boards would
 * pass by asserting zero against zero — which is why `tests/Pest.php` seeds it
 * before every Feature test.
 *
 * Deliberately SMALL for the same reason: every one of the ~1,500 feature tests
 * pays for these rows.
 *
 * The values are provisional by design (Q4) and are tuned from /admin after a
 * month of real behaviour, which is why they are rows rather than constants.
 */
class GamificationCatalogSeeder extends Seeder
{
    /** @var list<array{key: string, name_ar: string, xp: int, coins: int, daily_cap: int|null}> */
    private const ACTIONS = [
        ['key' => 'session_attended', 'name_ar' => 'حضور حصة', 'xp' => 10, 'coins' => 5, 'daily_cap' => 4],
        ['key' => 'homework_submitted', 'name_ar' => 'تسليم واجب', 'xp' => 15, 'coins' => 5, 'daily_cap' => 3],
        ['key' => 'exam_passed', 'name_ar' => 'اجتياز اختبار', 'xp' => 50, 'coins' => 20, 'daily_cap' => 2],
        ['key' => 'mistake_resolved', 'name_ar' => 'إصلاح خطأ سابق', 'xp' => 8, 'coins' => 2, 'daily_cap' => 10],

        /*
        | Spec 010 — the teacher endorsed an answer in a public room (FR-023).
        |
        | ⚠️ THE ROW IS THE FEATURE. `AwardPoints` looks an action up by key and
        | returns SILENTLY when there is none, so shipping the event, the listener
        | and the button without this line awards nothing at all — and every
        | assertion about it passes by comparing zero with zero.
        |
        | Capped low: the point is to reward a good explanation, and an
        | uncapped one is a teacher able to mint a term's worth of XP for one
        | student in an afternoon.
        */
        ['key' => 'helpful_answer', 'name_ar' => 'إجابة اعتمدها المدرّس', 'xp' => 20, 'coins' => 10, 'daily_cap' => 3],

        /*
        | ⚠️ ZERO COINS, AND THAT IS NOT AN OVERSIGHT. A focus session belongs to
        | no teacher, so there is no workspace to hold the coins — and a coin
        | balance is per teacher by design (FR-028ج). AwardPoints refuses a
        | coin-bearing action with no workspace for exactly this reason: there is
        | no correct purse to put them in.
        */
        ['key' => 'focus_session', 'name_ar' => 'جلسة تركيز مكتملة', 'xp' => 5, 'coins' => 0, 'daily_cap' => 6],

        /*
        | The negative action. It lives in the catalogue as a ROW with a signed
        | value, not as a branch in the awarding code — which is what keeps the
        | penalty visible to the operator editing the values.
        */
        ['key' => 'payment_overdue', 'name_ar' => 'تأخّر في الدفع', 'xp' => -30, 'coins' => 0, 'daily_cap' => 1],

        /*
        | Spec 011 — a friend was invited and actually subscribed (FR-020 · FR-024).
        |
        | ⚠️ ZERO COINS, FOR `focus_session`'s REASON EXACTLY. A referral belongs
        | to no teacher, so there is no workspace to hold coins — and `AwardPoints`
        | THROWS on a coin-bearing action with a null workspace rather than
        | guessing a purse. Give this row coins and every completed referral on the
        | platform becomes a 500 inside a queued listener.
        |
        | ⚠️ AND `daily_cap` IS NULL, WHICH IS NOT LAZINESS. Past a daily cap
        | `AwardPoints` returns null — so a capped referral would be flipped
        | `completed` with nothing awarded: completed-but-unpaid, invisible, and
        | unrepeatable because the flip is a one-way conditional UPDATE. The
        | governor for this action is the PLATFORM cap
        | (`referral.max_completed_per_referrer`), which is checked BEFORE the flip
        | and refuses the completion rather than the payment.
        |
        | The value here is seeded from `referral.reward_points` on a live
        | database and is authoritative afterwards — see the backfill migration.
        */
        ['key' => 'invite_friend', 'name_ar' => 'دعوة صديق اشترك فعلاً', 'xp' => 50, 'coins' => 0, 'daily_cap' => null],
    ];

    /** @var list<array{level: int, name_ar: string, xp_threshold: int}> */
    private const LEVELS = [
        ['level' => 1, 'name_ar' => 'مبتدئ', 'xp_threshold' => 0],
        ['level' => 2, 'name_ar' => 'مجتهد', 'xp_threshold' => 100],
        ['level' => 3, 'name_ar' => 'متقدّم', 'xp_threshold' => 300],
        ['level' => 4, 'name_ar' => 'متمكّن', 'xp_threshold' => 700],
        ['level' => 5, 'name_ar' => 'متميّز', 'xp_threshold' => 1500],
        ['level' => 6, 'name_ar' => 'خبير', 'xp_threshold' => 3000],
    ];

    /** @var list<array{key: string, name_ar: string, icon: string, rule_type: BadgeRuleType, rule_value: int, rule_action_key: string|null}> */
    private const BADGES = [
        ['key' => 'first_steps', 'name_ar' => 'الخطوة الأولى', 'icon' => 'sparkles', 'rule_type' => BadgeRuleType::TotalXp, 'rule_value' => 50, 'rule_action_key' => null],
        ['key' => 'committed', 'name_ar' => 'مواظب', 'icon' => 'fire', 'rule_type' => BadgeRuleType::StreakDays, 'rule_value' => 7, 'rule_action_key' => null],
        ['key' => 'regular_attender', 'name_ar' => 'حاضر دائم', 'icon' => 'calendar', 'rule_type' => BadgeRuleType::ActionCount, 'rule_value' => 20, 'rule_action_key' => 'session_attended'],
        ['key' => 'climber', 'name_ar' => 'صاعد', 'icon' => 'trophy', 'rule_type' => BadgeRuleType::LevelReached, 'rule_value' => 3, 'rule_action_key' => null],
    ];

    public function run(): void
    {
        $this->write(overwrite: true);
    }

    /**
     * Only the rows that are missing — the shape a DEPLOY needs.
     *
     * ⚠️ `run()` OVERWRITES, AND THAT IS RIGHT FOR DEVELOPMENT AND WRONG FOR A
     * RELEASE. Every row here is editable from `/admin`
     * ({@see GamificationActionResource}),
     * so an `updateOrCreate` in the deploy path resets every xp value, coin value
     * and daily cap an operator ever tuned — on every release, silently.
     *
     * ⚠️ AND THE OPPOSITE IS WORSE, WHICH IS WHY THIS EXISTS AT ALL. `AwardPoints`
     * looks an action up by key and returns in SILENCE when there is no row — an
     * award for an undefined action is an unfilled catalogue, not an error. So a
     * release that adds an action and seeds nothing ships a feature that is dead
     * on arrival: `helpful_answer` was in this file and absent from a real
     * database, and a teacher endorsing a student's answer awarded nothing, with
     * no error anywhere. Found by walking the product, not by a test — the suite
     * seeds this table before every case, so every assertion about it was made
     * against a catalogue production did not have.
     */
    public function seedMissing(): void
    {
        $this->write(overwrite: false);
    }

    private function write(bool $overwrite): void
    {
        foreach (self::ACTIONS as $action) {
            $overwrite
                ? GamificationAction::query()->updateOrCreate(['key' => $action['key']], [...$action, 'is_active' => true])
                : GamificationAction::query()->firstOrCreate(['key' => $action['key']], [...$action, 'is_active' => true]);
        }

        foreach (self::LEVELS as $level) {
            $overwrite
                ? Level::query()->updateOrCreate(['level' => $level['level']], $level)
                : Level::query()->firstOrCreate(['level' => $level['level']], $level);
        }

        foreach (self::BADGES as $badge) {
            $overwrite
                ? Badge::query()->updateOrCreate(['key' => $badge['key']], [...$badge, 'is_active' => true])
                : Badge::query()->firstOrCreate(['key' => $badge['key']], [...$badge, 'is_active' => true]);
        }
    }
}
