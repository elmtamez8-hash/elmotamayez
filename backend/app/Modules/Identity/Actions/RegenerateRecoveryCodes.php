<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorCodes;
use App\Shared\Actions\Action;
use DomainException;

/**
 * A fresh set, and the old set stops working the moment it is issued.
 *
 * Keeping both alive would mean a printed sheet someone lost stays valid after
 * they replaced it, which is the whole reason to regenerate.
 */
class RegenerateRecoveryCodes extends Action
{
    public function __construct(private readonly TwoFactorCodes $codes) {}

    /**
     * `$code` is a live authenticator code or an unused recovery code — the
     * check `DisableTwoFactor` makes, in the Action so no second caller can skip
     * it. The password is the Request's half, as it is for disabling.
     *
     * @return array<int, string>
     */
    public function handle(User $user, string $code): array
    {
        $secret = $user->getAppAuthenticationSecret();

        if ($secret === null || ! $user->hasTwoFactorEnabled()) {
            throw new DomainException('فعّل التحقق بخطوتين أولاً.');
        }

        if (! $this->codes->verify($secret, $code) && ! $this->codes->consumeRecoveryCode($user, $code)) {
            throw new DomainException('الرمز غير صحيح.');
        }

        $recoveryCodes = $this->codes->generateRecoveryCodes();

        $this->codes->storeRecoveryCodes($user, $recoveryCodes);

        return $recoveryCodes;
    }
}
