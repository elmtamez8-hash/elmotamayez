<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageQuestions', $this->route('exam')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:mcq,true_false,text'],
            'difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
            'content' => ['required', 'string'],
            'points' => ['nullable', 'integer', 'min:0'],
            'explanation' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'options.*.content' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
