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

    /** @return array<int, string> */
    public function handle(User $user): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new DomainException('فعّل التحقق بخطوتين أولاً.');
        }

        $recoveryCodes = $this->codes->generateRecoveryCodes();

        $this->codes->storeRecoveryCodes($user, $recoveryCodes);

        return $recoveryCodes;
    }
}
