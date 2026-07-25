<?php

declare(strict_types=1);

namespace App\Shared\Scopes;

use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that restricts tenant-scoped models to the current workspace.
 *
 * Filtering is skipped when WorkspaceContext has no current workspace (i.e. operating
 * globally, typically a Super Admin with no workspace selected). To bypass the scope
 * on a specific query, call {@see BelongsToWorkspace::withoutWorkspaceScope()}.
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return;
        }

        $builder->where(
            $model->getTable().'.'.$model->getWorkspaceColumn(),
            '=',
            $workspaceId,
        );
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutWorkspaceScope', function (Builder $builder): Builder {
            return $builder->withoutGlobalScope($this);
        });
    }
}
