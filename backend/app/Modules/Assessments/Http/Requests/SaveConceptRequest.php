<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Models\Concept;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $concept = $this->route('concept');

        if ($concept instanceof Concept) {
            return $this->user()?->can('update', $concept) ?? false;
        }

        return $this->user()?->can(Permissions::QUESTIONS_MANAGE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $concept = $this->route('concept');

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                // Two concepts with one name is a taxonomy that cannot be filtered
                // by: the teacher picks one of two identical rows and sees half
                // their questions. Scoped by hand rather than through
                // WorkspaceRules because that helper builds `exists`, not `unique`.
                Rule::unique('concepts', 'name')
                    ->where('workspace_id', app(WorkspaceContext::class)->id())
                    ->ignore($concept instanceof Concept ? $concept->getKey() : null),
            ],
            'subject_id' => ['nullable', 'uuid', WorkspaceRules::exists('subjects', 'uuid')],
        ];
    }
}
