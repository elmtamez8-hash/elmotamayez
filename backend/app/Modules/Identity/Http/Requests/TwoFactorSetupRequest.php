<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Enrolling and un-enrolling both re-ask for the password, so a borrowed session
 * cannot quietly replace the second factor with one the borrower controls.
 */
class TwoFactorSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
        ];
    }

    public function ensureCurrentPasswordIsValid(): void
    {
        $user = $this->user();

        if ($user === null || ! Hash::check((string) $this->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ]);
        }
    }
}
