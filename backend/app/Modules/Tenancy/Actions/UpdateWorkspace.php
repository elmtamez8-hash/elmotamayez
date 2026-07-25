<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

class UpdateWorkspace extends Action
{
    use LogsActivity;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Workspace $workspace, array $attributes): Workspace
    {
        $workspace->update($attributes);

        $this->logActivity('updated', $workspace, $attributes);

        $workspace->refresh();

        return $workspace;
    }
}
