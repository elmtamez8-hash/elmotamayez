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
 *
 * @implements Scope<Model>
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return;
        }

        // The scope is only ever registered from BelongsToWorkspace, which supplies
        // the column; the fallback keeps the type checker honest.
        $column = method_exists($model, 'getWorkspaceColumn')
            ? $model->getWorkspaceColumn()
            : 'workspace_id';

        $builder->where(
            $model->getTable().'.'.$column,
            '=',
            $workspaceId,
        );
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutWorkspaceScope', function (Builder $builder): Builder {
            return $builder->withoutGlobalScope(self::class);
        });
    }
}
