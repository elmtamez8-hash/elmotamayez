<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'original_filename' => ['required', 'string', 'max:255'],
            // Declared by the client, so it is a courtesy check that saves a
            // doomed gigabyte transfer — not the real limit, which is applied to
            // the file itself on completion.
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
