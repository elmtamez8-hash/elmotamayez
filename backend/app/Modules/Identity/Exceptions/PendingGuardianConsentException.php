<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A minor tried to sign in before their guardian consented (FR-003 · FR-009ب).
 *
 * ⚠️ IT RENDERS ITSELF, so every sign-in path answers identically without each
 * controller having to remember. There are three today — password, two-factor
 * exchange, and the panel — and a rule spelled in one of them is a rule the other
 * two get wrong.
 *
 * ⚠️ AND THE CODE IS MACHINE-READABLE ON PURPOSE. The frontend has to show a
 * specific screen — "ask your guardian to approve" — rather than the generic
 * credentials message, and matching on a translated sentence is a screen that
 * breaks the day somebody rewords it. Same shape as `two_factor_required`.
 */
class PendingGuardianConsentException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'حسابك بانتظار موافقة وليّ أمرك على معالجة بياناتك.',
            'code' => 'pending_guardian_consent',
        ], 403);
    }
}
