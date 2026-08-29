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

            /*
            | ⚠️ SHAPE ONLY, AND NO `exists:` RULE. An unknown code must not fail
            | a registration — a typo off a poster blocking a real person from
            | creating an account is the most hostile thing this feature could
            | do — so `AttachReferral` attaches nothing and says nothing. An
            | `exists:` rule would also be an oracle: a 422 for an unknown code
            | and a 201 for a real one enumerates who is on the platform.
            */
            'referral_code' => ['nullable', 'string', 'max:12'],
        ];
    }
}
