<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\RoleResource\Pages;

use App\Modules\Tenancy\Filament\Resources\RoleResource;
use App\Modules\Tenancy\Models\Role;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * ⚠️ الصلاحيّاتُ تُمنَحُ بـ`syncPermissions()` لا بكتابةٍ على جدولِ الوصل.
     *
     * هي البابُ الذي يرفضُ صلاحيّةَ منصّةٍ لدورِ مساحةِ عمل، ويمسحُ ذاكرةَ spatie
     * معاً. والحفظُ يسبقُه لأنّ الصفَّ يجبُ أن يوجدَ قبلَ أن يُوصَل — وكلاهما في
     * معاملةٍ واحدة، وإلّا بقيَ دورٌ بلا صلاحيّةٍ بعدَ رفضٍ في منتصفِ الطريق.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_filter(
            (array) ($data['permissions'] ?? []),
            static fn (mixed $value): bool => is_string($value),
        ));

        unset($data['permissions']);

        // اسمُ الحارسِ صريحٌ ولا يُترَكُ للافتراضِ: عمودٌ في مفتاحِ التفرّد.
        $data['guard_name'] = 'web';

        return DB::transaction(function () use ($data, $permissions): Model {
            /*
            | ⚠️ `Role::query()->create()` لا `Role::create()`: الثانيةُ من spatie
            | بلا نوعِ إرجاعٍ مُعلَن، فلا يعرفُ المحلِّلُ أنّها نموذج. والباني
            | مُعرَّفٌ بنوعٍ عامٍّ صحيح. وما تُضيفُه spatie في طريقِها ثلاثةُ أشياءَ
            | نُغطّيها هنا صراحةً: اسمُ الحارسِ مكتوبٌ أعلاه، ومساحةُ العملِ حقلٌ
            | في النموذج، وتكرارُ الاسمِ تمنعُه قاعدةُ `unique` في الحقلِ وفهرسُ
            | قاعدةِ البيانات.
            */
            $record = Role::query()->create($data);

            if ($permissions !== []) {
                $record->syncPermissions($permissions);
            }

            return $record;
        });
    }

    /**
     * ⚠️ رفضُ النموذجِ جوابٌ للمشغِّل، لا صفحةُ خطأٍ بيضاء.
     *
     * `Role::creating` يرمي `DomainException` عندَ اسمٍ محجوزٍ لدورِ منصّة،
     * و`syncPermissions()` يرميه عندَ صلاحيّةِ منصّة. وبلا هذا الالتقاطِ تصيرُ
     * قاعدةُ عملٍ مكتوبةٌ بعنايةٍ خطأَ خادمٍ لا يقرؤه أحد — نفسُ العيبِ الذي
     * سُجِّلَ في `CancelClassSession`: `DomainException` يرثُ `LogicException`،
     * فلا يلتقطُه أيُّ حارسٍ يترقّبُ `RuntimeException`.
     */
    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('لم يُنشَأ الدور')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }
    }
}
