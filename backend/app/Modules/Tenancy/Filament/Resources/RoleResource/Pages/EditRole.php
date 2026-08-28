<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\RoleResource\Pages;

use App\Modules\Tenancy\Filament\Resources\RoleResource;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Roles;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * الصلاحيّاتُ المُعلَّمةُ تُقرأُ بأسمائِها، لأنّ القائمةَ مفاتيحُها أسماء.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();

        $data['permissions'] = $record->permissions->pluck('name')->all();

        return $data;
    }

    /**
     * ⚠️ **الحفظُ يمرُّ من `syncPermissions()` وحدَه.**
     *
     * `->relationship()` على القائمةِ كان سيجعلُ Filament يكتبُ على جدولِ الوصلِ
     * مباشرةً، فيتجاوزُ الجدارَ الذي يمنعُ صلاحيّةَ منصّةٍ أن تصلَ دورَ مساحةِ
     * عمل — وهو الجدارُ الذي `RolePermissionMatrix` كلُّه مبنيٌّ عليه: مدرّسٌ
     * يمنحُ نفسَه تقريرَ التحصيلِ وسقفَ الائتمانِ بنقرةٍ واحدة. ويتجاوزُ معه مسحَ
     * ذاكرةِ spatie، فتبقى الصلاحيّةُ القديمةُ سارِيةً حتّى انتهاءِ الذاكرة.
     *
     * ⚠️ **واسمُ الدورِ الافتراضيِّ يُسقَطُ من البياناتِ هنا، لا في الحقلِ وحدَه.**
     * `disabled()` حاجزُ واجهةٍ يتجاوزُه طلبُ Livewire مصنوعٌ باليد، وإعادةُ
     * تسميةِ `teacher` تكسرُ كلَّ `hasRole('teacher')` في الشجرةِ ولا يُعيدُها
     * `SeedDefaultRoles` — وهو سببُ رفضِ حذفِها نفسُه.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_filter(
            (array) ($data['permissions'] ?? []),
            static fn (mixed $value): bool => is_string($value),
        ));

        unset($data['permissions'], $data['team_id']);

        if ($record instanceof Role && in_array($record->name, Roles::workspaceRoles(), true)) {
            unset($data['name']);
        }

        return DB::transaction(function () use ($record, $data, $permissions): Model {
            $record->fill($data)->save();

            if ($record instanceof Role) {
                $record->syncPermissions($permissions);
            }

            return $record;
        });
    }

    /**
     * ⚠️ الرفضُ جوابٌ للمشغِّل. {@see CreateRole::create()} للسببِ نفسِه.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('لم تُحفَظ التغييرات')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }
    }
}
