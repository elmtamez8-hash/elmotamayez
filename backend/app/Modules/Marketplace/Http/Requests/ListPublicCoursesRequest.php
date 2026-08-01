<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPublicCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint. The guard is publiclyListed() inside the Action.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var array{max_per_page: int} $limits */
        $limits = config('marketplace.pagination');

        return [
            // Slugs, not ids: the same subject is a separate row per workspace, so
            // no exists rule applies (see ListPublicTeachersRequest).
            'subject' => ['sometimes', 'string', 'max:100'],
            'grade_level' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', Rule::in(Course::types())],
            'price_min' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'numeric', 'min:0', 'gte:price_min'],
            'teacher' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in([
                CourseFilterDTO::SORT_POPULAR,
                CourseFilterDTO::SORT_PRICE,
                CourseFilterDTO::SORT_NEWEST,
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
            'type.in' => 'نوع الكورس غير معروف.',
            'sort.in' => 'خيار الترتيب غير معروف.',
        ];
    }

    public function toDto(): CourseFilterDTO
    {
        return CourseFilterDTO::fromArray($this->validated());
    }
}
