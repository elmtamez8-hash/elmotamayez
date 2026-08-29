<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Requests;

use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Analytics\Support\ReportCadence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReportSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The permission is checked in the controller, beside the read that
        // shares it: one answer to "may you see the platform's numbers".
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // At least one, because a subscription to nothing is a nightly
            // notification whose body is "لا مؤشّرات مختارة" — a reminder that
            // the person forgot to choose, sent for ever.
            'metric_keys' => ['required', 'array', 'min:1'],
            'metric_keys.*' => ['string', Rule::in(MetricKey::values())],
            'cadence' => ['required', Rule::enum(ReportCadence::class)],
            'is_active' => ['boolean'],
        ];
    }
}
