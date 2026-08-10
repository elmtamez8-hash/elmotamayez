<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. That the end is not before the start is checked in the Action as
 * well, because the Filament panel and any future console command reach it too —
 * and a rule that lives only on the HTTP path is a rule with a door beside it.
 *
 * No `after_or_equal:today` on the start: a teacher setting up on the morning of
 * the first paper would be refused for naming today, and backdating a window is
 * harmless — coverage is asked about TODAY, so a window that ended last week
 * covers nothing at all.
 */
class StoreExamModeWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
        ];
    }
}
