<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Actions\AdvanceBreachReport;
use App\Modules\Compliance\Enums\BreachStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What an officer may write onto a report during triage (FR-040).
 *
 * ⚠️ THE THREE TIMESTAMPS ARE NOT INPUTS. `authority_notified_at` and
 * `subjects_notified_at` are the record of WHEN two legal obligations were
 * discharged, measured against a deadline — a field that let the officer type the
 * moment would let it be typed inside the window on a day it was not. The request
 * carries booleans; {@see AdvanceBreachReport}
 * stamps the clock, once.
 */
class AdvanceBreachReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(BreachStatus::class)],

            // `present` is not required: an officer recording a notification is not
            // restating the scope every time.
            'affected_categories' => ['sometimes', 'nullable', 'array'],
            'affected_categories.*' => ['string', 'max:64'],
            'affected_subject_count' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'authority_notified' => ['sometimes', 'boolean'],
            'subjects_notified' => ['sometimes', 'boolean'],
        ];
    }

    public function status(): BreachStatus
    {
        return BreachStatus::from((string) $this->input('status'));
    }

    /**
     * @return array{affected_categories?: list<string>|null, affected_subject_count?: int|null, authority_notified?: bool, subjects_notified?: bool}
     */
    public function triage(): array
    {
        /** @var array{affected_categories?: list<string>|null, affected_subject_count?: int|null, authority_notified?: bool, subjects_notified?: bool} $triage */
        $triage = [];

        if ($this->has('affected_categories')) {
            $categories = $this->input('affected_categories');

            // Rebuilt rather than passed through: validation guarantees an array of
            // strings, and a DTO that trusts a request array is one rule change away
            // from writing whatever arrived into a json column.
            $triage['affected_categories'] = is_array($categories)
                ? array_values(array_map(strval(...), $categories))
                : null;
        }

        if ($this->has('affected_subject_count')) {
            $count = $this->input('affected_subject_count');
            $triage['affected_subject_count'] = $count === null ? null : (int) $count;
        }

        if ($this->boolean('authority_notified')) {
            $triage['authority_notified'] = true;
        }

        if ($this->boolean('subjects_notified')) {
            $triage['subjects_notified'] = true;
        }

        return $triage;
    }
}
