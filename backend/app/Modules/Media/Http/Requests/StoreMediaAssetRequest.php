<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Both optional with a default, so the video page that predates 016
            // keeps working unchanged through the transition.
            'kind' => ['nullable', Rule::enum(MediaKind::class)],
            'role' => ['nullable', Rule::enum(MediaRole::class)],
        ];
    }

    public function kind(): MediaKind
    {
        return MediaKind::tryFrom((string) $this->input('kind')) ?? MediaKind::Video;
    }

    public function role(): MediaRole
    {
        return MediaRole::tryFrom((string) $this->input('role')) ?? MediaRole::Primary;
    }
}
