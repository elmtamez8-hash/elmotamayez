<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

class TwoFactorDisableRequest extends TwoFactorSetupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
