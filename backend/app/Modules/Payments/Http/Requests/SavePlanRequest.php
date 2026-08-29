<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ⚠️ `price_minor` IS ABSENT FROM THESE RULES AND STILL REFUSED IN THE ACTION.
 * Validation that simply drops an unlisted key is silent: a teacher who types a
 * price, is told nothing and finds out when nobody can buy. `SavePlan` throws a
 * sentence instead, and it has to, because the panel and any future importer
 * reach it with no form behind them at all.
 *
 * The course is validated for PRESENCE here and for OWNERSHIP in the Action:
 * `exists:courses,uuid` is a raw query with no global scope on it, so it answers
 * yes for every course on the platform.
 */
class SavePlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:730'],
            'session_type' => ['required', Rule::enum(ClassSessionType::class)],
            'coverage_type' => ['required', Rule::enum(PlanCoverage::class)],
            'coverage_uuid' => ['nullable', 'uuid', 'required_if:coverage_type,course'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
