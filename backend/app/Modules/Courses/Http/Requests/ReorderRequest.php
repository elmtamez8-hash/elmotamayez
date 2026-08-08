<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\StructureVersion;
use Illuminate\Foundation\Http\FormRequest;

class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'structure_version' => ['required', 'integer', 'min:1'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
        ];
    }

    /** @return list<string> */
    public function orderedUuids(): array
    {
        /** @var list<string> $order */
        $order = array_values($this->array('order'));

        return $order;
    }

    /** Shared with the publish path — see StructureVersion. */
    public function assertVersionMatches(Course $course): void
    {
        StructureVersion::assertMatches($course, $this->integer('structure_version'));
    }
}
