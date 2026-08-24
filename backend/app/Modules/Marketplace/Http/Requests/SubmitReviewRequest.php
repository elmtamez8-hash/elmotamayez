<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The real gates are the ReviewPolicy check and the attendance rule in
        // ReviewEligibility; both need the teacher, which the controller resolves.
        return true;
    }

    /**
     * ⚠️ THERE IS NO `rating` FIELD ANY MORE (FR-031). The overall star is the
     * average of the three axes, computed in `SubmitReview` — accepted here as
     * well, it would be a second answer to a question the axes already answer, and
     * the public star would drift from the bars beneath it at the first submission
     * where the two disagreed.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'punctuality' => ['required', 'integer', 'between:1,5'],
            'clarity' => ['required', 'integer', 'between:1,5'],
            'engagement' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'punctuality.required' => 'قيّم الالتزام بالمواعيد.',
            'clarity.required' => 'قيّم جودة الشرح.',
            'engagement.required' => 'قيّم التفاعل.',
            'punctuality.between' => 'التقييم يجب أن يكون بين نجمة وخمس نجوم.',
            'clarity.between' => 'التقييم يجب أن يكون بين نجمة وخمس نجوم.',
            'engagement.between' => 'التقييم يجب أن يكون بين نجمة وخمس نجوم.',
            'comment.max' => 'التعليق طويل جداً.',
        ];
    }
}
