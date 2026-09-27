<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;

use App\Models\User;
use App\Modules\CMS\Actions\SaveArticle;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\CMS\Models\Article;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditCmsArticle extends EditRecord
{
    protected static string $resource = CmsArticleResource::class;

    /**
     * The save goes through {@see SaveArticle}, as the API's does: a cleared slug
     * keeps the one the article has, a published article keeps its date, and
     * the publish fields ask `cms.publish` when they move.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Article $record */
        $officer = Auth::user();

        if (! $officer instanceof User) {
            throw new AuthorizationException;
        }

        try {
            return app(SaveArticle::class)->handle($officer, $data, $record);
        } catch (AuthorizationException $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();

            throw new Halt;
        }
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
