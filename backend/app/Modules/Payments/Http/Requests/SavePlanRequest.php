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
            /*
            | ⛔ NEITHER SHAPE IS REQUIRED HERE, AND THAT IS NOT A LOOSENING.
            | «Exactly one of these two» is enforced in `SavePlan`, where the
            | Action is the single entrance the panel, the API and any seeder all
            | share. Left `required`, this door refused a session-shaped plan with
            | a 422 BEFORE the Action was ever reached — so the repository's own
            | rule that «the Action is the common entrance» was true of the Action
            | and false of the validation in front of it.
            |
            | What stays is the RANGE of each: a shape that is sent must be sane.
            */
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'session_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'session_type' => ['required', Rule::enum(ClassSessionType::class)],
            'coverage_type' => ['required', Rule::enum(PlanCoverage::class)],
            // ⚠️ AND THE COHORT COVERAGE NEEDS ITS UUID EXACTLY AS THE COURSE ONE
            // DOES. Without the second value here a group plan passes validation
            // with an empty coverage and is refused deeper in, by a message about
            // a field this form never marked.
            'coverage_uuid' => ['nullable', 'uuid', 'required_if:coverage_type,course', 'required_if:coverage_type,cohort'],
            'is_active' => ['sometimes', 'boolean'],
            /*
            | ٠٣٦ · FR-013 — «نعم، أعرف أنّ مجموعة ستخرج من العرض».
            |
            | ⚠️ NOT A COLUMN AND NOT `$fillable`: the Action reads it out of
            | `$data` exactly as it reads `is_active`, and `Plan` has no such
            | attribute to fill. It is the SECOND request of a two-step — the
            | first is refused with the count by `PlanWouldHideCohorts`, having
            | written nothing.
            */
            'acknowledge_hidden_cohorts' => ['sometimes', 'boolean'],
        ];
    }
}
