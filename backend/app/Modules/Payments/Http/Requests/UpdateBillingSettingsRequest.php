<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ZeroBalanceBehavior;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ⚠️ Every rule here validates the SHAPE, never the policy.
 *
 * `Rule::enum` refuses a value the enum does not have; whether the platform can
 * actually run a mode it does have is FR-015, and that lives in the Action —
 * see UpdateBillingSettings. Putting it here would leave the panel, which shares
 * the Action but not this class, able to save a mode with no way to take money.
 *
 * Every field is `sometimes`, so a PATCH carrying one key changes one thing.
 */
class UpdateBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::BILLING_SETTINGS_MANAGE) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::enum(BillingMode::class)],
            'cadence' => ['sometimes', Rule::enum(BillingCadence::class)],
            'zero_balance_behavior' => ['sometimes', Rule::enum(ZeroBalanceBehavior::class)],

            // A threshold list may be emptied — a workspace that wants no alerts
            // is a policy, not an omission — so `present` rather than `filled`.
            'alert_thresholds' => ['sometimes', 'array', 'max:5'],
            'alert_thresholds.*' => ['integer', 'min:0', 'max:1000'],
        ];
    }
}
