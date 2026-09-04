<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Modules\Identity\Actions\UpdateAccountDetails;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * ⚠️ `handleRecordUpdate` موجودٌ لكي لا ينادِيَ Filament `$record->update($data)`.
 * وحدَه هذا يجعلُ إسقاطَ توثيقِ البريدِ عندَ تغييرِه قراراً في مكانٍ واحد
 * ({@see UpdateAccountDetails})، لا سطراً في صفحةٍ يُنسى في الصفحةِ التالية.
 *
 * ولا زرَّ حذف: {@see UserResource::canDelete()} يرفض، وعقدُ `PersonalDataOwner`
 * هو السبب.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return app(UpdateAccountDetails::class)->handle($record, $data);
    }
}
