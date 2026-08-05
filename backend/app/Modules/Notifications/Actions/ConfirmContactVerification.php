<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Models\ContactVerification;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\Hash;

class ConfirmContactVerification extends Action
{
    public function handle(ContactVerification $verification, string $code): ContactVerification
    {
        if ($verification->isVerified()) {
            return $verification;
        }

        if ($verification->isExpired()) {
            throw new DomainException('انتهت صلاحية الرمز. اطلب رمزاً جديداً.');
        }

        if (! $verification->hasAttemptsLeft()) {
            throw new DomainException('تجاوزت عدد المحاولات المسموح. اطلب رمزاً جديداً.');
        }

        // Counted before the comparison, so a crash mid-check cannot hand back a
        // free attempt.
        $verification->increment('attempts');

        if (! Hash::check($code, $verification->code_hash)) {
            throw new DomainException('الرمز غير صحيح.');
        }

        $verification->forceFill(['verified_at' => now()])->save();

        return $verification;
    }
}
