<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gates are the ReviewPolicy check and the completed-session rule
        // in SubmitReview; both need the teacher, which the controller resolves.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.required' => 'التقييم مطلوب.',
            'rating.between' => 'التقييم يجب أن يكون بين نجمة وخمس نجوم.',
            'comment.max' => 'التعليق طويل جداً.',
        ];
    }
}
