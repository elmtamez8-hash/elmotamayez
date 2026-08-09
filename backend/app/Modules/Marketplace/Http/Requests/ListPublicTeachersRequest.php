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
            /*
            | ⚠️ REFUSED, not ignored (spec 006, FR-021و · T083).
            |
            | A range filter is a price oracle even with no price on the card:
            | binary search on `price_min` reads any teacher's rate to the riyal in a
            | dozen requests. So the parameter cannot merely stop being read — a
            | rule dropped from this array would leave the query string accepted
            | and silently ineffective, which reads to a caller as a filter that
            | works and to a reviewer as a filter that is gone.
            |
            | `prohibited` answers 422 and names the field, so an old bookmark
            | fails loudly instead of quietly returning an unfiltered list.
            */
            'price_min' => ['prohibited'],
            'price_max' => ['prohibited'],
            'min_rating' => ['sometimes', 'numeric', 'between:1,5'],
            'min_trust_score' => ['sometimes', 'integer', 'between:0,100'],
            'language' => ['sometimes', 'string', 'max:5'],
            'available_now' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in([
                TeacherFilterDTO::SORT_RATING,
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
            'price_min.prohibited' => 'لم يعد التصفية بالسعر متاحة.',
            'price_max.prohibited' => 'لم يعد التصفية بالسعر متاحة.',
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
