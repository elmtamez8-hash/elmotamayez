<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /**
     * Title and nothing else.
     *
     * `order` used to be accepted here. It is not any more: position is written
     * by the reorder endpoint, which receives the whole sibling list and so
     * cannot express a duplicate. Two ways to write the same column is how one
     * of them ends up bypassing the rule the other enforces — and this column
     * decides what a student may open.
     *
     * `status` is likewise absent: a new node is a draft, and publishing is its
     * own decision with its own endpoint.
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
