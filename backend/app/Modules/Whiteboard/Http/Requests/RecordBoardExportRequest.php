<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use App\Shared\Support\WorkspaceRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The board's PDF, already uploaded as an attachment of `lesson` (story 5). A
 * replacement names no lesson: it is the export's own.
 */
class RecordBoardExportRequest extends FormRequest
{
    /** The board's door before the body's: an intruder learns nothing from a 422. */
    public function authorize(): Response
    {
        return Gate::inspect('export', $this->route('board'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lesson' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', WorkspaceRules::exists('lessons', 'uuid')],
            'asset' => ['required', 'uuid', WorkspaceRules::exists('media_assets', 'uuid')],
        ];
    }
}
