<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkspaceResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLinkAction;
use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * صفحةُ قراءةٍ لمكانِ عمل — ولا تعديلَ فيها: {@see WorkspaceResource::canEdit()}
 * يرفض، والاسمُ والمالكُ يُكتبانِ من `CreateWorkspace` وحدَه.
 *
 * وهي ما يفتحُه البحثُ العامّ: قبلَها كانَ يجدُ المكانَ ولا يجدُ أين يفتحُه.
 */
class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            RecordLinkAction::make('owner', 'حساب المالك', UserResource::class, fn (): ?User => $this->workspace()?->owner, Heroicon::OutlinedUser),
            WorkspaceResource::participationAction(),
        ];
    }

    private function workspace(): ?Workspace
    {
        $record = $this->getRecord();

        return $record instanceof Workspace ? $record : null;
    }
}
