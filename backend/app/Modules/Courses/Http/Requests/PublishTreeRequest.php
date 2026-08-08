<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\StructureVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublishTreeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /**
     * Note what is NOT validated here: whether each item carries the fields its
     * type needs. That check reads the stored row, not the payload — the teacher
     * is publishing what they already saved — so it belongs to the Action, which
     * is also the entry point Filament and the seeders share.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'structure_version' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.uuid' => ['required', 'string'],
            'items.*.status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /** @return list<array{uuid: string, status: string}> */
    public function items(): array
    {
        $items = [];

        /** @var array<int, array<string, mixed>> $raw */
        $raw = $this->array('items');

        foreach ($raw as $item) {
            $items[] = [
                'uuid' => (string) $item['uuid'],
                'status' => (string) $item['status'],
            ];
        }

        return $items;
    }

    public function assertVersionMatches(Course $course): void
    {
        StructureVersion::assertMatches($course, $this->integer('structure_version'));
    }
}
