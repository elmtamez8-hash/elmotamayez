<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            /*
            | ⚠️ OPTIONAL, and that is a decision rather than a leftover: an
            | academy founder registers with nothing in hand, and
            | `AcademySignupUnchangedTest` calls that «the only way an academy
            | gets onto the platform». When a token IS sent it is a claim, and
            | the Action checks it (pending · unexpired · addressed to this
            | email · a staff role).
            */
            'invitation' => ['nullable', 'string', 'max:255'],
        ];
    }
}
