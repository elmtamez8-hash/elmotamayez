<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Identity\Support\TwoFactorCodes;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Turns it off — behind the current password *and* a live code.
 *
 * Requiring both is the point: a stolen session that could switch the second
 * factor off would make it decorative, and the password alone is exactly what
 * the factor exists to stop being sufficient.
 */
class DisableTwoFactor extends Action
{
    public function __construct(
        private readonly TwoFactorCodes $codes,
        private readonly TerminateOtherSessions $terminateOthers,
    ) {}

    public function handle(User $user, string $code, ?int $keepTokenId = null): void
    {
        $secret = $user->getAppAuthenticationSecret();

        if ($secret === null || ! $user->hasTwoFactorEnabled()) {
            throw new DomainException('التحقق بخطوتين غير مفعَّل على هذا الحساب.');
        }

        if (! $this->codes->verify($secret, $code) && ! $this->codes->consumeRecoveryCode($user, $code)) {
            throw new DomainException('الرمز غير صحيح.');
        }

        $user->saveAppAuthenticationSecret(null);
        $this->codes->storeRecoveryCodes($user, null);

        $user->securitySettings()->updateOrCreate([], ['two_factor_confirmed_at' => null]);
        $user->unsetRelation('securitySettings');

        $this->terminateOthers->handle(
            $user,
            SessionEndReason::TwoFactorChange,
            'أُلغي التحقق بخطوتين على حسابك. إن لم تكن أنت، غيّر كلمة المرور فوراً.',
            $keepTokenId,
        );
    }
}
