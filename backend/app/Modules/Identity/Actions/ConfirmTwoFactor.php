<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\SessionEndReason;
use App\Modules\Identity\Support\TwoFactorCodes;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Step two: prove the authenticator holds the secret, then turn it on.
 *
 * Recovery codes are generated here and returned once. They are stored hashed,
 * so this response is the only place they exist in readable form — a "show them
 * again" endpoint could not be built without weakening the storage.
 */
class ConfirmTwoFactor extends Action
{
    public function __construct(
        private readonly TwoFactorCodes $codes,
        private readonly TerminateOtherSessions $terminateOthers,
    ) {}

    /** @return array<int, string> */
    public function handle(User $user, string $code, ?int $keepTokenId = null): array
    {
        $secret = $user->getAppAuthenticationSecret();

        if ($secret === null) {
            throw new DomainException('ابدأ التفعيل أولاً ثم أدخل الرمز.');
        }

        if ($user->hasTwoFactorEnabled()) {
            throw new DomainException('التحقق بخطوتين مفعَّل بالفعل.');
        }

        if (! $this->codes->verify($secret, $code)) {
            throw new DomainException('الرمز غير صحيح. تأكّد من وقت جهازك وحاول مجدداً.');
        }

        $recoveryCodes = $this->codes->generateRecoveryCodes();
        $this->codes->storeRecoveryCodes($user, $recoveryCodes);

        $user->securitySettings()->updateOrCreate([], ['two_factor_confirmed_at' => now()]);
        $user->unsetRelation('securitySettings');

        // FR-031: the second factor changed, so every session that predates it
        // goes — including any an intruder opened with the password alone.
        $this->terminateOthers->handle(
            $user,
            SessionEndReason::TwoFactorChange,
            'فُعِّل التحقق بخطوتين على حسابك، وأُنهيت الجلسات الأخرى.',
            $keepTokenId,
        );

        return $recoveryCodes;
    }
}
