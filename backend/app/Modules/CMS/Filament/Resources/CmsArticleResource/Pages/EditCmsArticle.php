<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;

use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCmsArticle extends EditRecord
{
    protected static string $resource = CmsArticleResource::class;

    /**
     * ⚠️ A CLEARED SLUG FIELD MUST NOT BLANK THE COLUMN. `doNotGenerateSlugsOnUpdate()`
     * means nothing regenerates it, so an empty string here would save a blank
     * slug — a published URL turned into `/blog/` and, at the second one, a
     * collision on the platform-wide unique index.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['slug'] ?? '') === '') {
            unset($data['slug']);
        }

        if (($data['status'] ?? 'draft') === 'published' && ($data['published_at'] ?? null) === null) {
            $data['published_at'] = now();
        }

        return $data;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
