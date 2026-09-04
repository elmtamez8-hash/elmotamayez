<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Models\User;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

class CreateWorkspaceRequest extends FormRequest
{
    /**
     * Spec 025 · FR-007.
     *
     * ⚠️ This used to read `return $this->user() !== null;` — no permission, no
     * role, no count. Measured with a student account holding zero permissions:
     * three workspaces created back to back, `201` each time, and `tenant-owner`
     * with 68 permissions inside every one of them. «No owner has two workspaces
     * in production» was true of the data and was never a rule anything enforced.
     *
     * ⚠️ AND THE FORM REQUEST IS NOT THE GUARD, only its first line. A rule that
     * lives only here is bypassed by the Filament panel and by every seeder, which
     * is why {@see CreateWorkspace} enforces the
     * one-workspace-per-owner rule itself — the Action is the entrance the API,
     * the panel and the seeds all share.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->user()->can('create', Workspace::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:teacher,academy,school'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:workspaces,slug'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
