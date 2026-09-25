<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources\LegalHoldResource\Pages;

use App\Models\User;
use App\Modules\Compliance\Actions\PlaceLegalHold;
use App\Modules\Compliance\Filament\Resources\LegalHoldResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;

class ListLegalHolds extends ListRecords
{
    protected static string $resource = LegalHoldResource::class;

    /**
     * «وضع تعليق»: الفعلُ نفسُه الذي يستدعيه `POST /manage/compliance/holds`.
     *
     * ⚠️ المُنتقي على `users` كلِّها بلا نطاق، وهذا صحيح لا تسريب: `LegalHold`
     * و`User` كلاهما بلا `BelongsToWorkspace`، والتعليقُ يخصُّ شخصاً في المنصّةِ كلِّها
     * لا في مساحةِ عملٍ واحدة — وحاملُ `compliance.holds.manage` لا يحملُه أيُّ دورِ
     * مساحة (`LegalHoldPolicy`).
     *
     * ⚠️ والقيمةُ `uuid` لا المفتاحُ التسلسليّ: هو ما يقبلُه المسارُ وما يُطبَعُ في
     * كلِّ حمولةٍ في هذا المنتَج.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('place')
                ->label('وضع تعليق')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (): bool => LegalHoldResource::canManage())
                ->modalHeading('وضع تعليق قانونيّ')
                ->modalDescription('يمنع حذف بيانات هذا الشخص — بطلب منه أو بكنس الاحتفاظ الليليّ — حتى يُرفع. '
                    .'ويُوقَف فوراً أيّ طلب حذف مفتوح له.')
                ->modalSubmitActionLabel('ضع التعليق')
                ->schema([
                    Select::make('subject')
                        ->label('صاحب البيانات')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => User::query()
                            ->where(fn ($q) => $q->where('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%"))
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (User $u): array => [(string) $u->uuid => self::label($u)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($u = User::query()->where('uuid', $value)->first()) === null
                            ? null
                            : self::label($u))
                        ->helperText('ابحث بالاسم أو البريد أو الهاتف.'),
                    // Required, as the API requires it: `ReleaseLegalHold` gives the
                    // next officer nothing to weigh without it.
                    Textarea::make('reason')
                        ->label('السبب')
                        ->required()
                        ->maxLength(2000)
                        ->helperText('أمرٌ قضائيّ أو التزامٌ قانونيّ — يُحفظ مع التعليق ويقرؤه من يرفعه.'),
                ])
                ->action(function (array $data): void {
                    abort_unless(LegalHoldResource::canManage(), 403);

                    $subject = User::query()->where('uuid', (string) $data['subject'])->firstOrFail();

                    /** @var User $officer */
                    $officer = auth()->user();

                    app(PlaceLegalHold::class)->handle($subject, $officer, trim((string) $data['reason']));
                }),
        ];
    }

    private static function label(User $user): string
    {
        $contact = $user->email ?? $user->phone;

        return $contact === null ? $user->name : $user->name.' — '.$contact;
    }
}
