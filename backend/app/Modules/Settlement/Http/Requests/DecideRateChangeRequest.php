<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideRateChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required on the reject route, which is the one that reaches here
            // with a decision the teacher has to be able to act on (FR-013أ).
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
