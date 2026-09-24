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
            | ⛔ REQUIRED since 2026-09-24 (owner decision). It was optional for
            | ONE reason — an academy founder registered with nothing in hand —
            | and spec 025 · FR-026 abolished new founders, so the optionality
            | served nobody and left a side door: anyone typing `/register` got
            | an account with no role, no date of birth and no guardian gate,
            | and could buy courses. Students, guardians and teachers each have
            | their own signup under `/signup/*`. The token is still CHECKED in
            | the Action (pending · unexpired · addressed to this email · a
            | staff role); this rule only says one must be presented.
            */
            'invitation' => ['required', 'string', 'max:255'],

            /*
            | ⚠️ SHAPE ONLY, AND NO `exists:` RULE. An unknown code must not fail
            | a registration — a typo off a poster blocking a real person from
            | creating an account is the most hostile thing this feature could
            | do — so `AttachReferral` attaches nothing and says nothing. An
            | `exists:` rule would also be an oracle: a 422 for an unknown code
            | and a 201 for a real one enumerates who is on the platform.
            |
            | ⚠️ THE STUDENT DOOR ANSWERS DIFFERENTLY ON PURPOSE. `/signup/student`
            | carries a visible «كود الإحالة» field, so `RegisterStudentRequest`
            | refuses an unknown code with a 422 under it (see the reasons there).
            | This door has no such field on any screen, so there is nobody to
            | show a message to.
            */
            'referral_code' => ['nullable', 'string', 'max:12'],
        ];
    }
}
