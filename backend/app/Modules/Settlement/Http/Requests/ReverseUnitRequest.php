<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseUnitRequest extends FormRequest
{
    /** Authorisation is the policy's job, on the route. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required, not optional. FR-006 asks the correction to carry its
            // reason, and a reversal with no reason is exactly the row somebody
            // will be asked to explain a year later.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
