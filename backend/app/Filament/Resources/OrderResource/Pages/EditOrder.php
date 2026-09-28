<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLinkAction;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Models\Workspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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
            OrderResource::reverseAction(),
            OrderResource::settleRefundAction(),
            // روابطُ لا قرارات — كلٌّ يظهرُ لمن يفتحُ هدفَه وحدَه.
            RecordLinkAction::make('openUser', 'حساب المشتري', UserResource::class, fn (): ?User => $this->order()?->user, Heroicon::OutlinedUser),
            RecordLinkAction::make('openWorkspace', 'مكان العمل', WorkspaceResource::class, fn (): ?Workspace => $this->order()?->workspace, Heroicon::OutlinedBuildingLibrary),
            RecordLinkAction::make('openCourse', 'الكورس', CourseResource::class, fn (): ?Course => $this->order()?->course, Heroicon::OutlinedAcademicCap),
        ];
    }

    private function order(): ?Order
    {
        $record = $this->getRecord();

        return $record instanceof Order ? $record : null;
    }
}
