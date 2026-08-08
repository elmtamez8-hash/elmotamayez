<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /**
     * Renaming only — see StoreSectionRequest for why position is not writable
     * through the ordinary update path.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
