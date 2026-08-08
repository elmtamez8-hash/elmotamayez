<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http\Requests;

use App\Modules\Courses\Enums\ContentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The impact preview's input: a batch, or nothing.
 *
 * `items` is optional, and its absence is the common case — it means "everything
 * still in draft", which the Action derives from the tree and echoes back in its
 * answer. That is why the client does not send that list: the set the preview
 * costs and the set the publish receives must be the same set, and the only way
 * to guarantee it is for one side to produce it.
 *
 * The explicit form exists for the other direction. Unpublishing or archiving is
 * the same endpoint, and those are the batches `FR-053` and `FR-055` warn about
 * — neither is reachable from "publish every draft".
 *
 * The rules mirror `PublishTreeRequest` field for field, because whatever the
 * preview accepts the publish must accept too. What is NOT here, in either, is
 * whether an item carries the fields its type needs: that reads the stored row,
 * so it belongs to the Action — and both go through the same one.
 */
class PublishPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageLessons', $this->route('course')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.uuid' => ['required', 'string'],
            'items.*.status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /** @return list<array{uuid: string, status: string}>|null */
    public function items(): ?array
    {
        if (! $this->has('items')) {
            return null;
        }

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
}
