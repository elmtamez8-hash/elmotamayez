<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Actions\UpdateAccountDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ⛔ A NEW ADDRESS COSTS THE CURRENT PASSWORD.
 *
 * The address is where the reset link goes, so changing it is changing who owns
 * the account. Under a bearer token alone, one minute with a stolen token was
 * enough to point the account at another inbox, ask for a reset link and keep
 * it. The password is the one thing a stolen token does not carry.
 *
 * A name change asks for nothing, and neither does the same address in another
 * case — {@see UpdateAccountDetails::emailDiffers()} is the one spelling of
 * «different», shared with the Action that writes the row.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email', 'max:255', "unique:users,email,{$userId}"],
            'current_password' => [Rule::requiredIf(fn (): bool => $this->changesEmail()), 'nullable', 'string'],
        ];
    }

    /**
     * Checked AFTER the rules, so a missing password answers «required» and a
     * wrong one answers «incorrect» — both under the field the screen shows.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->changesEmail() || $validator->errors()->has('current_password')) {
                    return;
                }

                $user = $this->user();

                if (! $user instanceof User || ! Hash::check((string) $this->input('current_password'), $user->password)) {
                    $validator->errors()->add('current_password', __('validation.current_password'));
                }
            },
        ];
    }

    /**
     * The fields to write — never the password that authorised them.
     *
     * @return array{first_name?: string, last_name?: string, email?: string}
     */
    public function profile(): array
    {
        /** @var array{first_name?: string, last_name?: string, email?: string} */
        return $this->safe()->except('current_password');
    }

    private function changesEmail(): bool
    {
        $user = $this->user();
        $email = $this->input('email');

        return $user instanceof User && is_string($email) && UpdateAccountDetails::emailDiffers($user, $email);
    }
}
