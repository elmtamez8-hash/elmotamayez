<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Support\AvailabilityRules;
use Illuminate\Foundation\Http\FormRequest;

class TeacherStepFourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'currency' => ['sometimes', 'string', 'size:3'],
            // ⚠️ مشتركةٌ مع `‎PUT /teacher/availability` — انظر {@see AvailabilityRules}.
            ...AvailabilityRules::fields(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return AvailabilityRules::messages();
    }
}
