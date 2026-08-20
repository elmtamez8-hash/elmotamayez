<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Requests;

use App\Modules\Gamification\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRewardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'price_coins' => ['required', 'integer', 'min:1'],
            'stock' => ['required', 'integer', 'min:0'],
            'reward_type' => ['required', Rule::in(RewardType::values())],
            /*
            | Nullable HERE, and mandatory for a money-valued type in the ACTION.
            | The rule cannot live only in this class: the seeder and the panel
            | reach the same write with no form behind them (FR-031 · SC-012).
            */
            'monthly_cap' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
