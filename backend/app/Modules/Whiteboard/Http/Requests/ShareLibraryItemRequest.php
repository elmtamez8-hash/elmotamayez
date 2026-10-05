<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use App\Modules\Whiteboard\Actions\ShareLibraryItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A shape shared to the academy's library: a name and the Excalidraw elements.
 * Their content is checked by `ShareLibraryItem` (the page's element rules).
 */
class ShareLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'elements' => ['required', 'array', 'min:1', 'max:'.ShareLibraryItem::MAX_ELEMENTS],
        ];
    }
}
