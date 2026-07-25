<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkspaceCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $owner,
    ) {}
}
