<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إعطاءُ مَن يقفُ للمنصّةِ مهلةَ تفعيلِ التحقّقِ بخطوتَين — لأنّه لم يُعطَها قطّ.
 *
 * ⚠️ THE MANDATE HAD TWO CALLERS AND BOTH WERE WORKSPACE PATHS.
 * `TwoFactorMandate::applyTo()` is reached from `AcceptInvitation` and
 * `CreateWorkspace` and from nowhere else, so an account whose privilege is
 * PLATFORM standing — `users.is_super_admin`, or a row in `platform_staff` —
 * never had a deadline written for it. `RequireTwoFactor` reads that absence as
 * «still inside the grace period» and passes, which is correct behaviour over
 * incorrect data: measured on production 2026-09-03, the platform's ONLY super
 * admin had no `user_security_settings` row, so the `2fa.required` middleware on
 * `/orders/{uuid}/approve` and `/reject` was guarding nobody at all — and those
 * two routes are the ones that just became the platform's own money decision.
 *
 * ⚠️ THE STAMP IS `now() + grace_days`, NEVER `now()` AND NEVER THE PAST. These
 * people were told no deadline, so enforcing one retroactively would refuse the
 * platform's only approver on the first request after this deploys. The class's
 * own docblock draws the same line for the opposite case — a deadline somebody
 * was already told about must not move — and the grace period is read from
 * `platform_settings` rather than written here, or the number an operator tunes
 * and the number this file used would be two answers.
 *
 * ⚠️ AND IT WRITES ONLY WHERE THERE IS NO DEADLINE YET. `updateOrCreate`-shaped
 * on purpose: an officer who already enrolled, or who holds a workspace role and
 * was mandated through it, keeps the date they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        $days = max(0, (int) PlatformSettings::get('auth.two_factor_grace_days', 14));
        $deadline = now()->addDays($days);

        $userIds = DB::table('users')
            ->where('is_super_admin', true)
            ->pluck('id')
            ->merge(DB::table('platform_staff')->pluck('user_id'))
            ->unique()
            ->all();

        foreach ($userIds as $userId) {
            $existing = DB::table('user_security_settings')->where('user_id', $userId)->first();

            if ($existing === null) {
                DB::table('user_security_settings')->insert([
                    'user_id' => $userId,
                    'two_factor_required_at' => $deadline,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            // A date already written is a date somebody was already told.
            if ($existing->two_factor_required_at === null) {
                DB::table('user_security_settings')
                    ->where('user_id', $userId)
                    ->update(['two_factor_required_at' => $deadline, 'updated_at' => now()]);
            }
        }
    }

    /**
     * ⚠️ THIS CANNOT DISTINGUISH WHAT IT WROTE, AND SAYS SO. Nulling every
     * platform holder's deadline would also clear one earned through a workspace
     * role, which this migration never touched. Nothing is lost by leaving them:
     * a deadline on an account that should have one is the correct state either
     * way, and `applyTo()` would write it again on the next grant.
     */
    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
