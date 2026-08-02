<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\DTOs\TeacherFilterDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint. The guard is publiclyListed() inside the Action, not
        // authorization — there is no actor to authorize.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var array{max_per_page: int} $limits */
        $limits = config('marketplace.pagination');

        return [
            // Taxonomy filters are matched by slug, so there is no exists rule here.
            // WorkspaceRules::exists would scope to the current workspace, and a
            // guest has none — it would either pass everything or nothing.
            'subject' => ['sometimes', 'string', 'max:100'],
            'grade_level' => ['sometimes', 'string', 'max:100'],
            'price_min' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'numeric', 'min:0', 'gte:price_min'],
            'min_rating' => ['sometimes', 'numeric', 'between:1,5'],
            'min_trust_score' => ['sometimes', 'integer', 'between:0,100'],
            'language' => ['sometimes', 'string', 'max:5'],
            'available_now' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in([
                TeacherFilterDTO::SORT_RATING,
                TeacherFilterDTO::SORT_PRICE,
                TeacherFilterDTO::SORT_TRUST,
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$limits['max_per_page']],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'price_max.gte' => 'الحد الأعلى للسعر يجب أن يكون أكبر من الحد الأدنى.',
            'min_rating.between' => 'التقييم يجب أن يكون بين 1 و5.',
            'min_trust_score.between' => 'درجة الثقة يجب أن تكون بين 0 و100.',
            'sort.in' => 'خيار الترتيب غير معروف.',
        ];
    }

    public function toDto(): TeacherFilterDTO
    {
        return TeacherFilterDTO::fromArray($this->validated());
    }
}
