<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adjusting one design: its field positions, its selection, or both.
 *
 * ⚠️ THE STRUCTURE OF `boxes` IS NOT VALIDATED HERE, DELIBERATELY. `SaveFieldBoxes`
 * enforces the six keys, the ranges, `x + w ≤ 1`, `min_font ≤ max_font` and the
 * closed colour list — because the seeder and the Filament panel reach that Action
 * with no form behind them (المبدأ الثاني). Repeating those rules here would be a
 * second spelling that goes stale, and a rule enforced in the form ALONE is one the
 * panel walks straight past.
 *
 * ⚠️ AND `nullable` IS LOAD-BEARING: `boxes: null` means «go back to the template's
 * own positions» (`FR-021`), which is a different request from omitting the key.
 * The controller tells them apart with `has()`, which is the only thing that can.
 */
class SaveBoxesRequest extends FormRequest
{
    /** Authorisation is the policy, applied in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'boxes' => ['sometimes', 'nullable', 'array'],
            'is_selected' => ['sometimes', 'boolean'],
        ];
    }
}
