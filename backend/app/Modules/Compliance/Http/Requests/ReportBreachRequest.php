<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an outside reporter may write (FR-040 · SC-020).
 *
 * ⚠️ TWO FIELDS, AND EVERY OMISSION IS DELIBERATE. The scope of the incident —
 * which categories, how many people — belongs to triage: accepted here, anyone
 * could assert the size of an incident into our own record of it. `status` is
 * likewise absent, because a reporter who could open a report already `closed` is
 * a reporter who can hide one.
 *
 * ⚠️ AND `reporter_contact` CARRIES NO `email` RULE AND NO `exists`. A researcher
 * may leave a handle, a phone, a PGP fingerprint or nothing at all, and a
 * validation error that fired only for addresses which happen to hold an account
 * would be the oracle this whole route is shaped to avoid.
 */
class ReportBreachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'reporter_contact' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.min' => 'صِفِ الحادثَ بما يكفي لفحصه — ماذا رأيتَ وأين.',
        ];
    }

    public function description(): string
    {
        return trim((string) $this->input('description'));
    }

    public function reporterContact(): ?string
    {
        $contact = trim((string) $this->input('reporter_contact', ''));

        return $contact === '' ? null : $contact;
    }
}
