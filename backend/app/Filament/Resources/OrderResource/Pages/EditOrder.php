<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Modules\Payments\Actions\ApproveOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * ⛔ هذه الصفحةُ كانت تعرضُ الإيصالَ ولا تحملُ قراراً.
     *
     * تعليقُ قسمِ «الإيصال» يقولُ «افتحْها وطابقِ المبلغَ قبلَ الاعتماد»، والزرّانِ
     * كانا في الجدولِ وحدَه — فالشاشةُ التي تطلبُ المطابقةَ لم يكن فيها ما يُقرَّرُ
     * به. الطريقُ الوحيدُ الظاهرُ عليها كان حقلَ الحالةِ في النموذج، وهو الذي
     * يتخطّى {@see ApproveOrder} بأكملِه.
     *
     * ⚠️ ونفسُ الباني لا نسخةٌ ثانية: {@see OrderResource::approveAction()} تحملُ
     * `authorize()` وحاجزَ التحقّقِ بخطوتَين وشرطَ الظهور، وهجاءٌ ثانٍ هنا يفترقُ
     * عنها عندَ أوّلِ تعديل.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            OrderResource::approveAction(),
            OrderResource::rejectAction(),
        ];
    }
}
