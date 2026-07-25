<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'settings' => $this->settings,
            'is_owner' => $request->user()?->getKey() === $this->owner_user_id,
            'pivot_role' => $this->whenPivotLoaded('workspace_members', fn () => $this->pivot->role),
            'created_at' => $this->created_at,
        ];
    }
}
