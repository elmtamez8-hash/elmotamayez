<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;

use App\Models\User;
use App\Modules\CMS\Actions\SaveArticle;
use App\Modules\CMS\Filament\Resources\CmsArticleResource;
use App\Modules\Tenancy\Models\Workspace;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateCmsArticle extends CreateRecord
{
    protected static string $resource = CmsArticleResource::class;

    /**
     * ⛔ THE WHOLE WRITE GOES THROUGH {@see SaveArticle}, AND FILAMENT'S DEFAULT
     * IS REPLACED ENTIRELY.
     *
     * The default is `new Article($data)` then `save()`: `BelongsToWorkspace`
     * filled `workspace_id` from the OFFICER's context (their own
     * `last_workspace_id`) — an article on the wrong blog — or, for an officer
     * with none, a raw NOT NULL error; `author_id` stayed null; and the slug,
     * date and publish rules were a second spelling of the API's. The workspace
     * is the one the officer CHOSE on the form, never inferred.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $officer = Auth::user();
        $workspace = Workspace::query()->where('uuid', $data['workspace'] ?? null)->first();
        unset($data['workspace']);

        if (! $officer instanceof User || ! $workspace instanceof Workspace) {
            Notification::make()->danger()->title('اختَرْ مساحةَ العمل، لم يُكتَبْ شيء.')->send();

            throw new Halt;
        }

        try {
            return app(SaveArticle::class)->handle($officer, $data, null, (int) $workspace->getKey());
        } catch (DomainException|AuthorizationException $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();

            throw new Halt;
        }
    }
}
