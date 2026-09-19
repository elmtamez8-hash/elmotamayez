<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a teacher may ask for on a plan the platform has already priced (٠٣٦).
 *
 * ⛔ A SEPARATE FORM RATHER THAN A WIDENED `SavePlanRequest`, AND THE PRICE IS
 * WHY. That form deliberately does not declare `price_minor`, because a teacher
 * may not write one — and widening it to carry a price for this one route is how
 * the key eventually reaches the save path too. Here the number is a REQUEST, on
 * a row nothing sells from, decided by somebody who holds `plans.price`.
 *
 * ⚠️ AND THE PRICE IS OPTIONAL. «Change the shape, you decide the number» is the
 * ordinary arrangement (FR-025); a required price would force every teacher to
 * name one, which is the thing the platform reserves.
 *
 * `title` is absent on purpose: the teacher can already rename a priced plan
 * from their own screen without asking anybody.
 */
class RequestPlanChangeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The same ranges the writer accepts — «exactly one of the two» is
            // asked by `SavePlan::resolveShape()`, which this request's Action
            // calls rather than respelling.
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'session_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'session_type' => ['required', Rule::enum(ClassSessionType::class)],
            'coverage_type' => ['required', Rule::enum(PlanCoverage::class)],
            'coverage_uuid' => ['nullable', 'uuid', 'required_if:coverage_type,course', 'required_if:coverage_type,cohort'],
            'requested_price_minor' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
