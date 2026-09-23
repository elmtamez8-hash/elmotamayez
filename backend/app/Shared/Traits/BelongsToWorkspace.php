<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks an Eloquent model as tenant-scoped.
 *
 * On boot: registers {@see WorkspaceScope} and auto-fills `workspace_id` on creation.
 * Provides the `workspace` relation and a `withoutWorkspaceScope()` query helper.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(WorkspaceScope::class);

        static::creating(function (self $model): void {
            if ($model->getAttribute($model->getWorkspaceColumn()) === null) {
                $workspaceId = app(WorkspaceContext::class)->id();

                if ($workspaceId !== null) {
                    $model->setAttribute($model->getWorkspaceColumn(), $workspaceId);
                }
            }
        });
    }

    /**
     * The column used to partition records by workspace.
     */
    public function getWorkspaceColumn(): string
    {
        return 'workspace_id';
    }

    /**
     * Relationship to the owning workspace.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, $this->getWorkspaceColumn());
    }

    /**
     * The row behind a door a STUDENT walks through — resolved by uuid without
     * the workspace scope, then authorised by the caller on the next line.
     *
     * ⛔ NOT IMPLICIT BINDING. `WorkspaceContext::id()` falls back to
     * `users.last_workspace_id`, stamped on every student a teacher ever added to
     * their workspace — so the scope ANDs the OTHER teacher's id and the student's
     * own exam, homework, certificate or seat answers 404. Teacher-only routes
     * keep implicit binding: there the scope IS the tenant guard.
     */
    public static function forStudentDoor(string $uuid): static
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Bypass the workspace global scope for this query.
     *
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    public function scopeWithoutWorkspaceScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(WorkspaceScope::class);
    }
}
