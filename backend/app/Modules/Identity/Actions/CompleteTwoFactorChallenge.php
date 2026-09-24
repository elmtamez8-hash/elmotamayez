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
            $this->consumeRecoveryCode($user, $challenge, $recoveryCode);
        } else {
            $secret = (string) $user->getAppAuthenticationSecret();

            if ($code === null || ! $this->codes->verify($secret, $code)) {
                // The challenge survives a wrong code — mistyping six digits is
                // ordinary, and killing it would send the person back to the
                // password form. Up to a point: the fifth wrong answer spends it.
                $this->refuse($challenge, 'الرمز غير صحيح.');
            }
        }

        TwoFactorChallenges::forget($challenge);

        return $user;
    }

    /**
     * A wrong answer, counted — whichever of the two kinds it was.
     *
     * ⚠️ A wrong RECOVERY code counts against the same five. It is a guess at the
     * same door, and counting only one kind would hand the guesser a second
     * budget by switching fields.
     */
    private function refuse(string $challenge, string $message): never
    {
        if (TwoFactorChallenges::recordFailure($challenge)) {
            throw new DomainException('تجاوزت عدد المحاولات المسموح بها. سجّل الدخول من جديد.');
        }

        throw new DomainException($message);
    }

    /**
     * One use, then gone — and the account holder is told, because a recovery
     * code being spent is either them losing their phone or someone else holding
     * their printed sheet (FR-029).
     */
    private function consumeRecoveryCode(User $user, string $challenge, string $recoveryCode): void
    {
        if (! $this->codes->consumeRecoveryCode($user, $recoveryCode)) {
            $this->refuse($challenge, 'رمز الاسترداد غير صحيح أو استُخدم من قبل.');
        }

        $remaining = $this->codes->remainingRecoveryCodes($user);

        $this->notify->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::SecurityAlert,
            variables: [
                'name' => $user->name,
                'event' => 'استُخدم رمز استرداد لتسجيل الدخول إلى حسابك. المتبقّي: '.$remaining.'.',
            ],
            // Where the reader can see every signed-in device and end one.
            actionUrl: '/settings/security',
        ));
    }
}
