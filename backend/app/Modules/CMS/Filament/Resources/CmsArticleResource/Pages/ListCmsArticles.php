<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;

use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCmsArticles extends ListRecords
{
    protected static string $resource = CmsArticleResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
