<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use App\Modules\Community\Models\GradingScheme;
use Illuminate\Foundation\Http\FormRequest;

class SaveGradingSchemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', GradingScheme::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // ⚠️ NO `exists:courses,uuid`. A bare uuid rule is an identity probe:
            // it answers whether a course exists on the platform, in any
            // workspace. `SaveGradingScheme` resolves it inside the workspace.
            'course_uuid' => ['nullable', 'string', 'uuid'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'weights' => ['required', 'array'],
            // ⚠️ THE 100% RULE IS NOT HERE. It is enforced in the Action, which
            // is the entry point the seeders and the panel share with this
            // request — a rule that lives only in a payload shape holds for
            // whichever screen happens to exist today (FR-050, SC-017).
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
