<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'settings' => $this->settings,
            'is_owner' => $request->user()?->getKey() === $this->owner_user_id,
            // Which workspace the request is acting in — the client shouldn't have
            // to infer it from the membership list.
            'is_current' => app(WorkspaceContext::class)->id() === $this->getKey(),
            'pivot_role' => $this->whenPivotLoaded(
                'workspace_members',
                fn () => $this->resource->getRelationValue('pivot')?->getAttribute('role'),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
