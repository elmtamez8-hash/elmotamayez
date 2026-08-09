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
            /*
            | ⚠️ REFUSED, not ignored (spec 006, FR-021و · T083).
            |
            | A range filter is a price oracle even with no price on the card:
            | binary search on `price_min` reads any course's price to the riyal in a
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
            'teacher' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', Rule::in([
                CourseFilterDTO::SORT_POPULAR,
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
            'price_min.prohibited' => 'لم يعد التصفية بالسعر متاحة.',
            'price_max.prohibited' => 'لم يعد التصفية بالسعر متاحة.',
            'type.in' => 'نوع الكورس غير معروف.',
            'sort.in' => 'خيار الترتيب غير معروف.',
        ];
    }

    public function toDto(): CourseFilterDTO
    {
        return CourseFilterDTO::fromArray($this->validated());
    }
}
