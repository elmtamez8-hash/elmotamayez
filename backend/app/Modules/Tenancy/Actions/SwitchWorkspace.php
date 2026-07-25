<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;

class SwitchWorkspace extends Action
{
    public function __construct(
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(Workspace $workspace): void
    {
        $this->context->set($workspace);
    }
}
