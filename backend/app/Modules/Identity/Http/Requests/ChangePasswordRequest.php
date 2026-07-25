<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];
    }

    public function ensureCurrentPasswordIsValid(): void
    {
        $user = $this->user();

        if (! Hash::check($this->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ]);
        }
    }
}
