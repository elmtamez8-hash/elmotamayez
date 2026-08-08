<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    /**
     * Refuses a layout computed against a tree that has since changed.
     *
     * Answered with 409 and the current version, not 422: nothing about the
     * request was malformed. The client's map is out of date, and the fix is to
     * re-read the tree — which is a different instruction to the user than
     * "correct your input".
     */
    public function assertVersionMatches(Course $course): void
    {
        if ((int) $this->integer('structure_version') === (int) $course->structure_version) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'تغيّرت الشجرة منذ فتحتها. أعد تحميلها قبل الحفظ حتى لا يُدهس تعديل غيرك.',
            'structure_version' => $course->structure_version,
        ], 409));
    }
}
