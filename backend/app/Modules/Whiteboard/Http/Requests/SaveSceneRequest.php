<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Http\Requests;

use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One autosave. The body's size is checked from `Content-Length` BEFORE anything
 * decodes it: nginx admits bodies far larger than a page may be, and decoding a
 * 20 MB JSON body to refuse it would cost the memory the limit exists to save.
 * The Action re-checks the scene's own length.
 */
class SaveSceneRequest extends FormRequest
{
    /** Room for the envelope around the scene (tab, version, client_rev) and JSON escaping. */
    private const ENVELOPE_BYTES = 64 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $declared = (int) $this->header('Content-Length', '0');

        if ($declared > WhiteboardSettings::maxSceneBytes() * 2 + self::ENVELOPE_BYTES) {
            throw new WhiteboardRefusal('scene_too_large');
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tab' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'client_rev' => ['required', 'integer', 'min:0'],
            'scene' => ['required', 'string'],
        ];
    }
}
