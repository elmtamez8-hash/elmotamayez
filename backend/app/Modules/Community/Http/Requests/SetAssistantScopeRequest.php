<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Requests;

use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ⚠️ `WorkspaceRules::exists()`, NEVER `exists:courses,uuid`. Laravel's rule is a
 * raw query and runs outside the global scope, so the bare form accepts another
 * teacher's course uuid — and the confinement would then be written against a
 * course this workspace does not own, silently widening nothing and breaking
 * nothing visibly until somebody reads the table.
 */
class SetAssistantScopeRequest extends FormRequest
{
    /** Authorised by the policy in the controller — the row is what decides. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Present and empty is «no confinement», which is a legitimate thing
            // to ask for: it is how an owner takes a restriction back off.
            'courses' => ['present', 'array', 'max:200'],
            'courses.*' => ['string', WorkspaceRules::exists('courses', 'uuid')],
        ];
    }
}
