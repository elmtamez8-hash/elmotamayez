<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;

use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsArticle extends CreateRecord
{
    protected static string $resource = CmsArticleResource::class;

    /**
     * ⚠️ AN EMPTY SLUG MUST REACH THE MODEL AS NULL, NOT AS `''`.
     * `HasSlug` only generates when the field is empty AND unchanged from its
     * original — an empty STRING submitted by a cleared input reads as «the
     * author typed a slug», so the row would be saved with a blank one, and the
     * global `unique(slug)` then rejects the second article anybody writes with a
     * raw integrity error. `published_at` is stamped for the same reason the API
     * stamps it: the public predicate requires it, so an article created
     * `published` with no date is published to its author and invisible to
     * everyone else, with nothing on any screen to say so.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['slug'] ?? '') === '') {
            unset($data['slug']);
        }

        if (($data['status'] ?? 'draft') === 'published' && ($data['published_at'] ?? null) === null) {
            $data['published_at'] = now();
        }

        return $data;
    }
}
