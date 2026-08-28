<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Filament\Resources\RewardResource\Pages;
use App\Modules\Gamification\Filament\Resources\RewardResource\RelationManagers\RedemptionsRelationManager;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The teacher's shop, read only (FR-029 · FR-031).
 *
 * ⚠️ NOT A WRITE SCREEN, AND THAT IS THE SHAPE OF THE ROW ITSELF. `stock`,
 * `month_key` and `month_redeemed` are claimed by ONE conditional statement — the
 * seat idiom — and the last two are deliberately absent from `$fillable` for
 * exactly that reason. A Filament form writing them is a second way to release a
 * slot from outside the statement that owns it, and the monthly cap FR-031 calls
 * one of the four controls that cannot be skipped stops biting. Editing goes
 * through `SaveReward`, which the API and the seeder already share.
 *
 * ⚠️ AND THE DOOR IS DECLARED HERE, NOT LEFT TO THE POLICY. Filament's default
 * `viewAny` allows, and a Filament LIST never calls the row policy — a resource
 * with no explicit refusal is a resource with no guard, which is the leak
 * `OrderResource` shipped. `RewardPolicy` also has no `view()` method at all, so
 * a `ViewRecord` left to it would answer 403 to every teacher while a super admin
 * sailed past on `Gate::before` and the screen looked correct.
 */
class RewardResource extends Resource
{
    protected static ?string $model = Reward::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'التلعيب';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'متجر المكافآت';
    }

    public static function getModelLabel(): string
    {
        return 'مكافأة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المكافآت';
    }

    /**
     * عددُ الطلباتِ المعلّقةِ لكلِّ مكافأة — الرقمُ الوحيدُ الذي يُتصرَّف بناءً عليه.
     *
     * `withCount` داخل استعلامِ القائمة، لا `count()` داخل عمود: عمودُ Filament
     * يُنفَّذ مرّةً لكلِّ صفّ، فاستعلامٌ بداخله هو N+1 بالتعريف.
     *
     * ⚠️ `Builder<Model>` لا `Builder<Reward>`. `Resource` عامٌّ على `TModel`
     * وافتراضُه `Model`، و`parent::getEloquentQuery()` تُرجِعُ ذلك النوعَ نفسَه —
     * فوسمٌ بالنموذجِ الملموسِ هنا يَعِدُ بما لا يُرجِعُه الجسدُ فعلاً.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'redemptions as pending_redemptions_count' => function (Builder $query): void {
                $query->where('status', RedemptionStatus::Pending->value);
            },
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المكافأة')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->label('العنوان'),
                    TextEntry::make('type')->label('النوع')->badge()
                        ->formatStateUsing(fn (RewardType $state): string => $state->labelAr()),
                    TextEntry::make('price_coins')->label('السعر بالعملات'),
                    IconEntry::make('is_active')->label('معروضة')->boolean(),
                ]),

            Section::make('المخزون والسقف الشهري')
                ->description('العددان يُطالَبان بجملةٍ شرطيّةٍ واحدةٍ لحظةَ الاستبدال، ولا يُشتقّان من جدول الطلبات.')
                ->columns(2)
                ->schema([
                    TextEntry::make('stock')->label('المتبقّي'),
                    TextEntry::make('monthly_cap')->label('السقف الشهري')->placeholder('بلا سقف'),
                    TextEntry::make('month_key')->label('الشهر المحتسَب')->placeholder('—'),
                    TextEntry::make('month_redeemed')->label('المستبدَل هذا الشهر'),
                ]),

            Section::make('السجل')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('أُنشئت')->dateTime('Y-m-d H:i'),
                    TextEntry::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable(),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (RewardType $state): string => $state->labelAr()),
                TextColumn::make('price_coins')->label('السعر بالعملات')->sortable(),
                TextColumn::make('stock')->label('المتبقّي')->sortable(),
                TextColumn::make('monthly_cap')->label('السقف الشهري')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'بلا سقف' : (string) $state),
                TextColumn::make('month_redeemed')->label('المستبدَل هذا الشهر')
                    ->description(fn (Reward $record): string => $record->month_key === '' ? '—' : (string) $record->month_key),
                TextColumn::make('pending_redemptions_count')->label('طلبات معلّقة')->badge()->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                IconColumn::make('is_active')->label('معروضة')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(self::typeOptions()),
                TernaryFilter::make('is_active')->label('معروضة'),
            ]);
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (RewardType::cases() as $case) {
            $options[$case->value] = $case->labelAr();
        }

        return $options;
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            RedemptionsRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRewards::route('/'),
            'view' => Pages\ViewReward::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permissions::REWARDS_MANAGE);
    }

    /**
     * الصلاحيةُ وحدَها كافيةٌ هنا.
     *
     * المكافأةُ مملوكةٌ لمساحةِ عمل والقارئُ عضوٌ فيها، فالنطاقُ العامُّ يحسم
     * الملكيّةَ قبل أن تُستدعى هذه الدالّة: صفُّ مدرّسٍ آخر لا يُحَلُّ أصلاً.
     */
    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
