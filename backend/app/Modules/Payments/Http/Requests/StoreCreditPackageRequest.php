<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ⚠️ THERE IS NO PRICE FIELD, AND THERE CANNOT BE ONE.
 *
 * A package's price is computed per course from that course's teacher's approved
 * settlement rate (FR-021). A price accepted here would be one price for every
 * teacher on the platform — and, since this endpoint is reachable by the
 * platform rather than by a teacher, it would also be the platform quietly
 * setting what a teacher earns.
 *
 * `credits` has a ceiling because the sizes are configuration, not because 500 is
 * a meaningful number: an unbounded size makes the unredeemed ceiling (FR-021ي)
 * unreachable in a single purchase, which is the guard it exists to be.
 */
class StoreCreditPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::BILLING_PACKAGES_MANAGE) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'credits' => [$required, 'integer', 'min:1', 'max:500'],
            'session_type' => [$required, Rule::enum(ClassSessionType::class)],
            // Null is "never expires", the launch policy (Q-5) — so nullable
            // rather than absent, and absent means "leave it as it was".
            'validity_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
