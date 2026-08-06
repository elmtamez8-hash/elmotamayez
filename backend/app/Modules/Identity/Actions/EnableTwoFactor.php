<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorCodes;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Step one of enrolment: mint a secret and hand back the enrolment URI.
 *
 * The secret is stored immediately but `two_factor_confirmed_at` stays null, so
 * nothing changes about signing in until the person proves the authenticator
 * actually holds it. Storing it later instead would mean carrying it through the
 * client and back, which puts a secret in a place we do not control.
 */
class EnableTwoFactor extends Action
{
    public function __construct(private readonly TwoFactorCodes $codes) {}

    public function handle(User $user): string
    {
        if ($user->hasTwoFactorEnabled()) {
            // Re-enrolling silently would invalidate the working authenticator of
            // someone who merely reopened the setup screen.
            throw new DomainException('التحقق بخطوتين مفعَّل بالفعل. عطّله أولاً إن أردت تسجيل تطبيق آخر.');
        }

        $secret = $this->codes->generateSecret();

        $user->saveAppAuthenticationSecret($secret);

        return $this->codes->provisioningUri($user, $secret);
    }
}
