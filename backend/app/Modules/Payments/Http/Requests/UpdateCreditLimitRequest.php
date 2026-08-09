<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only — the cap, the consent gate and the reason are the Action's
 * (`SetCreditLimit`), because the panel and any future console command reach it
 * too.
 *
 * ⚠️ NO `exists:` RULE ON THE COURSE UUID. `exists:courses,uuid` is a raw query
 * past every scope, and here it would also be an identity probe: a 422 for an
 * unknown uuid and a 403 for a known one tells the caller which courses exist.
 * The lookup happens in the controller and the guard is participation, so the two
 * answers are indistinguishable from outside.
 *
 * `max` is deliberately absent as well: the platform's cap is a setting an
 * operator edits, and a number repeated here would be the copy that stayed at 4
 * after the cap moved.
 */
class UpdateCreditLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'course' => ['required', 'string', 'uuid'],
            'credit_limit_credits' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
