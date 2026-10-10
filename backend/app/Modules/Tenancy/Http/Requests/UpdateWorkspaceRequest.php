<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('workspace')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            /*
            | ⚠️ `unique` HERE TOO, AND IT WAS ONLY ON CREATE. The slug is the
            | public address of a workspace, so a rename could take a slug another
            | workspace already holds — the table has no unique index behind it,
            | so nothing else refuses, and whichever row the public lookup finds
            | first wins. `ignore()` is what keeps a save that does not change the
            | slug from failing against the workspace's own row.
            */
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('workspaces', 'slug')->ignore($this->route('workspace')),
            ],
            /*
            | ⛔ NO `settings` (security scan 2026-10-10, F2). It was `nullable|array`
            | and written whole, so an owner set `settings.billing.mode` — the
            | platform's call (`billing.settings.manage`) — and wiped what an
            | officer had set. Every key under it has its own guarded writer:
            | `BillingSettings`, `GradingSettings`, `TeacherInboxSettings`.
            */
        ];
    }
}
