<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Modules\Identity\Filament\Pages\CreateAccount;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * الإنشاءُ رابطٌ إلى الصفحةِ القائمة، لا زرُّ إنشاءٍ ثانٍ.
     *
     * ⚠️ {@see CreateAccount} موجودةٌ منذُ مواصفةِ الحسابات، وتنادي `RegisterStudent`
     * أو `RegisterParent` — الإجراءَ ذاتَه الذي ينادِيه بابُ التسجيلِ العلنيّ.
     * وكانت في القائمةِ الجانبيّةِ وحدَها: من فتحَ «المستخدمون» يبحثُ عن زرِّ
     * إضافةٍ هنا فلا يجدُه، ويستنتجُ أنّ المنصّةَ لا تُنشئُ حسابات. سطحٌ بلا رابطٍ
     * وارد هو سطحٌ غيرُ موجود.
     *
     * ⚠️ ولا `CreateRecord` بدلاً منها: صفحةُ إنشاءٍ في هذا المورِدِ نموذجٌ عامٌّ
     * يكتبُ `users` مباشرةً — بلا كلمةِ مرور، وبلا `platform_role`، وبلا بوّابةِ
     * القاصرِ في `RegisterStudent`. حسابانِ يُولَدانِ بطريقتَين مختلفتَين هو
     * عطلُ «إجابتَين لسؤالٍ واحد» في أخطرِ جدولٍ في المنتَج.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createAccount')
                ->label('إنشاء حساب')
                ->icon(Heroicon::OutlinedUserPlus)
                ->url(CreateAccount::getUrl())
                ->visible(fn (): bool => CreateAccount::canAccess()),
        ];
    }
}
