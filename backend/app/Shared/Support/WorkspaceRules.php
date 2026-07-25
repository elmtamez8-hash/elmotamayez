<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that respect the workspace boundary.
 *
 * Laravel's `exists` rule runs as a raw query builder lookup, so it bypasses the
 * {@see WorkspaceScope} global scope — an unscoped rule lets a
 * payload reference another tenant's row by its raw id.
 */
final class WorkspaceRules
{
    /**
     * An `exists` rule constrained to the current workspace. When no workspace is
     * current (Super Admin operating globally) the rule stays unconstrained, which
     * matches how the global scope behaves.
     */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        $workspaceId = app(WorkspaceContext::class)->id();

        return $workspaceId === null
            ? $rule
            : $rule->where('workspace_id', $workspaceId);
    }
}
