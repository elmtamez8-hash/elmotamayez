<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Filament\Resources\CreditPackageResource\Pages;
use App\Modules\Payments\Models\CreditPackage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The credit catalogue, administered where the platform's own data already is.
 *
 * ⚠️ THERE IS NO PRICE FIELD ON THIS SCREEN AND THERE CANNOT BE ONE. A package
 * is a SIZE; the price is `(that course's teacher's approved settlement rate + a
 * platform constant) × credits`, resolved per course at the moment of sale. A
 * number typed here would be one price for every teacher on the platform, which
 * is the thing cost-plus pricing exists to avoid (FR-021 · CostPlusPricing).
 *
 * ⚠️ AND NO TENANT ROLE REACHES IT, THE WORKSPACE OWNER INCLUDED. A package a
 * teacher can define is a sale price a teacher sets (FR-016 · FR-021ب), and spec
 * 014 pays that same teacher out of the credits their students consume — the
 * party who is paid cannot be the party who prices. Authorisation is the model
 * policy, so this screen and `/api/v1/admin/billing/packages` answer the same
 * question with the same code.
 *
 * ⚠️ RETIRE, NEVER DELETE. `is_active = false` is what the student's list reads;
 * the row is pointed at by every purchase ever made from it and by credits still
 * being consumed today. The three `canDelete*` refusals below are the ones that
 * hold — CreditPackagePolicy::delete() denies too, but BasePolicy::before()
 * waves a super-admin past any policy, and a super-admin is exactly who is
 * standing here.
 */
class CreditPackageResource extends Resource
{
    protected static ?string $model = CreditPackage::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'حزم الأرصدة';
    }

    public static function getModelLabel(): string
    {
        return 'حزمة أرصدة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'حزم الأرصدة';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255)
                // Unique because it is the seeder's identity key: a second row
                // called "أربع حصص فردية" makes the next reference-data run
                // ambiguous, and makes the student's list read as a duplicate.
                ->unique(ignoreRecord: true),

            TextInput::make('credits')
                ->label('عدد الحصص')
                ->numeric()
                ->required()
                ->minValue(1)
                ->helperText('رصيد واحد = حصة واحدة عند مدرّس واحد.'),

            Select::make('session_type')
                ->label('نوع الحصة')
                ->required()
                ->options(fn (): array => collect(ClassSessionType::cases())
                    ->mapWithKeys(fn (ClassSessionType $type): array => [$type->value => $type->label()])
                    ->all())
                ->helperText('يحدّد أي سعرٍ معتمَد يُقرأ للمدرّس؛ الحزمة لا تظهر لمن لا سعر معتمَد له في هذا النوع.'),

            TextInput::make('sort_order')
                ->label('الترتيب')
                ->numeric()
                ->default(0)
                ->required(),

            TextInput::make('validity_days')
                ->label('صلاحية الأرصدة بالأيام')
                ->numeric()
                ->minValue(1)
                ->helperText('اتركه فارغاً فلا تنتهي الصلاحية. وضعُ رقمٍ هنا يشغّل انتهاء الصلاحية فعليّاً: '
                    .'المكنسة الليلية ستسحب أرصدةً من طلاب اشتروها قبل أن تُعلَن هذه السياسة.'),

            Toggle::make('is_active')
                ->label('معروضة للبيع')
                ->default(true)
                ->helperText('إيقافها يخفيها عن شاشة الشراء ولا يمسّ رصيداً اشتُري منها.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('credits')->label('الحصص')->sortable(),
                TextColumn::make('session_type')->label('النوع')->badge()
                    ->formatStateUsing(fn (ClassSessionType $state): string => $state->label()),
                TextColumn::make('validity_days')->label('الصلاحية')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? 'بلا انتهاء'
                        : $state.' يوماً'),
                IconColumn::make('is_active')->label('معروضة')->boolean(),
                TextColumn::make('sort_order')->label('الترتيب')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('معروضة للبيع'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreditPackages::route('/'),
            'create' => Pages\CreateCreditPackage::route('/create'),
            'edit' => Pages\EditCreditPackage::route('/{record}/edit'),
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
