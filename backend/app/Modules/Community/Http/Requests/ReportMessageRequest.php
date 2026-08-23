<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Optional: a reporter who cannot put the problem into words is still
        // reporting something, and demanding a sentence is a reason not to.
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
