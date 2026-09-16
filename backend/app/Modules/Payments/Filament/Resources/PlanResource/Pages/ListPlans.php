<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\PlanResource\Pages;

use App\Modules\Payments\Filament\Resources\PlanResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    /**
     * ⛔ **كانَ هذا فارغاً، وتحتَه تعليقٌ يدافعُ عن قاعدةٍ ألغاها ٠٣٤.**
     *
     * الجملةُ المحذوفةُ كانت: «زرُّ باقةٍ جديدةٍ هنا يجعلُ الموظّفَ يخترعُ منتَجاً
     * في مساحةِ عملِ غيرِه». وهي حقُّ ما دامَ الإنشاءُ ممنوعاً — و`٠٣٤ · FR-017`
     * فتحَه عمداً: `PlanResource::canCreate()` صارَ `true`، وصفحةُ `CreatePlan`
     * كُتِبَت وتنادي الفعلَ وتسألُ الصلاحيّةَ داخلَها («إخفاءُ زرٍّ ليسَ حراسة»)،
     * وسُجِّلَت في `getPages()`.
     *
     * فبقيَ البابُ مفتوحاً بلا طريقٍ إليه: الصفحةُ تُفتَحُ بكتابةِ عنوانِها فقط.
     * قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦ حينَ احتاجَ مشغِّلٌ أوّلَ باقةٍ على المنصّةِ
     * — `plans` فيه صفرُ صفوفٍ — فلم يجدْ زرّاً.
     *
     * وهي ثالثُ مرّةٍ في هذا المستودعِ يُبنى فيها سطحٌ ولا يصلُ إليه شيء.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('باقة باسم مدرّس')];
    }
}
