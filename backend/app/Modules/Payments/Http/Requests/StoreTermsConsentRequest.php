<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\ConsentDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only, and notably NOT the version.
 *
 * A client that names the version it is accepting can accept a superseded text
 * for ever, which is FR-049 read backwards. The version is stamped server-side
 * from the registry at the moment of signing.
 *
 * `student` is optional: absent means the signer is signing for themselves,
 * which is the case the screen sends. Its presence is what triggers the guardian
 * check — done in the Action, where the Filament panel and any future console
 * caller reach it too.
 */
class StoreTermsConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => ['required', Rule::in(ConsentDocument::values())],

            // A plain string, deliberately not `exists:users,uuid`: a validation
            // error would answer "no such student" for someone else's child and
            // 422 would then be a probe for whether an account exists. Every
            // refusal on this route is the same 403.
            'student' => ['nullable', 'string', 'max:36'],
        ];
    }
}
