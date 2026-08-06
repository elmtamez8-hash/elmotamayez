<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorChallenges;
use App\Modules\Identity\Support\TwoFactorCodes;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Turns a challenge plus a code into the user it belongs to.
 *
 * Deliberately does not mint the token: the caller does that through
 * StartAuthSession, so a sign-in completed with a second factor goes through the
 * same device limit, the same session row and the same eviction alert as any
 * other. A second path to a Sanctum token is a second path to get wrong.
 */
class CompleteTwoFactorChallenge extends Action
{
    public function __construct(
        private readonly TwoFactorCodes $codes,
        private readonly DispatchNotification $notify,
    ) {}

    public function handle(string $challenge, ?string $code, ?string $recoveryCode): User
    {
        $userId = TwoFactorChallenges::userId($challenge);

        if ($userId === null) {
            throw new DomainException('انتهت مهلة التحقق. سجّل الدخول من جديد.');
        }

        $user = User::query()->find($userId);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            TwoFactorChallenges::forget($challenge);

            throw new DomainException('انتهت مهلة التحقق. سجّل الدخول من جديد.');
        }

        if ($recoveryCode !== null) {
            $this->consumeRecoveryCode($user, $recoveryCode);
        } else {
            $secret = (string) $user->getAppAuthenticationSecret();

            if ($code === null || ! $this->codes->verify($secret, $code)) {
                // The challenge survives a wrong code — mistyping six digits is
                // ordinary, and killing it would send the person back to the
                // password form. The throttle is what bounds the guessing.
                throw new DomainException('الرمز غير صحيح.');
            }
        }

        TwoFactorChallenges::forget($challenge);

        return $user;
    }

    /**
     * One use, then gone — and the account holder is told, because a recovery
     * code being spent is either them losing their phone or someone else holding
     * their printed sheet (FR-029).
     */
    private function consumeRecoveryCode(User $user, string $recoveryCode): void
    {
        if (! $this->codes->consumeRecoveryCode($user, $recoveryCode)) {
            throw new DomainException('رمز الاسترداد غير صحيح أو استُخدم من قبل.');
        }

        $remaining = $this->codes->remainingRecoveryCodes($user);

        $this->notify->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::SecurityAlert,
            variables: [
                'name' => $user->name,
                'event' => 'استُخدم رمز استرداد لتسجيل الدخول إلى حسابك. المتبقّي: '.$remaining.'.',
            ],
        ));
    }
}
