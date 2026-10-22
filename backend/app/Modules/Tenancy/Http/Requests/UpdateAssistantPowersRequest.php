<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Support\AssistantPowers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The door is `RolePolicy::update()` on the controller, asked of the row. */
class UpdateAssistantPowersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'groups' => ['present', 'array'],
            'groups.*' => ['string', Rule::in(array_keys(AssistantPowers::GROUPS))],
        ];
    }
}
