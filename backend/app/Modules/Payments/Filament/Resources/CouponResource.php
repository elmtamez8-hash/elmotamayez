<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Filament\Resources\CouponResource\Pages;
use App\Modules\Payments\Models\Coupon;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The platform's discount codes (spec 011 · T068 · FR-010).
 *
 * ⚠️ NO TENANT ROLE REACHES THIS SCREEN, THE WORKSPACE OWNER INCLUDED. A coupon
 * comes out of the platform's own commission, so a teacher who could write one
 * would be spending money that is not theirs — and `teacher_net_minor` is
 * computed from the LIST price precisely so their side never moves. The guard is
 * `CouponPolicy`, so this screen and any future endpoint answer the same
 * question with the same code.
 *
 * ⚠️ THE WORKSPACE FIELD IS LEFT EMPTY FOR A PLATFORM-WIDE CODE, and empty is the
 * ordinary case. Filling it narrows the code to one teacher's products — it is a
 * SCOPE, not an owner, and it is why this model deliberately carries no
 * `BelongsToWorkspace`.
 *
 * ⚠️ AND DELETION IS REFUSED HERE AS WELL AS IN THE POLICY. `BasePolicy::before()`
 * waves a super admin past every policy method, and a super admin is exactly who
 * is standing at this screen: the refusal that keeps the BUTTON off the page is
 * this one. Retirement is `is_active = false`, which the resolver reads on the
 * next purchase; the row stays because every `coupon_redemptions` entry made
 * from it is FR-015's record of a discount somebody actually received.
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'المال والاشتراكات';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return 'الكوبونات';
    }

    public static function getModelLabel(): string
    {
        return 'كوبون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الكوبونات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الكود وقيمته')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('الكود')
                        ->required()
                        ->maxLength(32)
                        ->unique(ignoreRecord: true)
                        // Normalised here as well as in the model: the panel is a
                        // second writer, and a code stored lower-case would never
                        // be found by a resolver that upper-cases what it is given.
                        ->dehydrateStateUsing(fn (string $state): string => Coupon::normaliseCode($state))
                        ->helperText('يُحوَّل إلى أحرفٍ كبيرةٍ تلقائيّاً، والمشتري يكتبه كيفما شاء.'),

                    Select::make('value_kind')
                        ->label('نوع الخصم')
                        ->required()
                        ->live()
                        ->default(CouponValueKind::Percent->value)
                        ->options(fn (): array => collect(CouponValueKind::cases())
                            ->mapWithKeys(fn (CouponValueKind $kind): array => [$kind->value => $kind->label()])
                            ->all()),

                    TextInput::make('value')
                        ->label('القيمة')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->helperText('نسبة: رقمٌ من ١ إلى ١٠٠. مبلغ ثابت: بالوحدة الصغرى، ويُقَصّ عند قيمة '
                            .'السطر فلا يهبط المبلغُ تحت الصفر.'),
                ]),

            Section::make('النطاق')
                ->description('اتركِ الحقلين فارغين ليسريَ الكوبون على كلِّ شيء. وتحديدُ مساحةِ عملٍ يقصره '
                    .'على مدرّسٍ واحد؛ وتحديدُ نوعٍ ومعرِّفٍ يقصره على شيءٍ واحدٍ بعينه.')
                ->columns(3)
                ->schema([
                    TextInput::make('workspace_id')
                        ->label('مساحة العمل')
                        ->numeric()
                        ->helperText('فارغ = كوبون منصّة يسري عند كلّ مدرّس.'),

                    Select::make('scope_type')
                        ->label('النوع')
                        ->options(fn (): array => collect(CouponScope::cases())
                            ->mapWithKeys(fn (CouponScope $scope): array => [$scope->value => $scope->label()])
                            ->all()),

                    TextInput::make('scope_uuid')
                        ->label('المعرّف')
                        ->maxLength(36)
                        ->helperText('معرّف الكورس أو المنتج أو الحزمة.'),
                ]),

            Section::make('المدّة والسقف')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('يبدأ')
                        ->seconds(false)
                        ->helperText('فارغ = ساري من الآن.'),

                    DateTimePicker::make('ends_at')
                        ->label('ينتهي')
                        ->seconds(false)
                        // The column is a TIMESTAMP and this picker writes one, so
                        // the last day is whole. A date field here would bind
                        // midnight and kill the coupon on the morning of its own
                        // final day.
                        ->helperText('فارغ = بلا انتهاء. والوقت محسوبٌ باللحظة، فآخرُ يومٍ يومٌ كامل.'),

                    TextInput::make('max_redemptions')
                        ->label('سقف الاستعمال')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('فارغ = بلا سقف. والسقفُ لا يُتجاوَز مهما تزامنت المحاولات.'),

                    Toggle::make('is_active')
                        ->label('فعّال')
                        ->default(true)
                        ->helperText('إيقافه يمنع أيَّ استعمالٍ جديدٍ ولا يمسّ خصماً مُنِح.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable()->sortable(),
                TextColumn::make('value')->label('القيمة')->formatStateUsing(
                    fn (int $state, Coupon $record): string => $record->value_kind === CouponValueKind::Percent
                        ? $state.'٪'
                        : (string) $state,
                ),
                TextColumn::make('scope_type')->label('النطاق')->badge()
                    ->formatStateUsing(fn (?CouponScope $state): string => $state?->label() ?? 'كل شيء'),
                TextColumn::make('workspace_id')->label('مساحة العمل')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'المنصّة' : (string) $state),
                // Read side by side on purpose: «١٢ من ٥٠» is the question an
                // operator actually has, and two separate columns make them
                // compare numbers on different rows of the same screen.
                TextColumn::make('redemptions_count')->label('الاستعمال')->sortable()
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->max_redemptions === null
                        ? $state.' (بلا سقف)'
                        : $state.' من '.$record->max_redemptions),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->sortable()
                    ->placeholder('بلا انتهاء'),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('فعّال'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
