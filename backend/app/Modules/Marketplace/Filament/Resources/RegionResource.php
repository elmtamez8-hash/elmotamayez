<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Filament\Resources\RegionResource\Pages;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use BackedEnum;
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
 * The regions a student picks at registration (spec 011 · FR-042).
 *
 * ⚠️ IT DOES NOT EXTEND {@see TaxonomyResource}, THOUGH IT SITS BESIDE ITS TWO
 * CHILDREN. That base carries an `icon` field and a teacher count read through a
 * `teacherProfiles` relation; a region has neither, so inheriting it would put a
 * column on the screen that is always empty and a relation that does not exist.
 * What IS shared is the decision — {@see TaxonomyPolicy} authorises all three
 * models, so no tenant role reaches any of them.
 *
 * ⚠️ AND NO DELETE, for the reason that policy already gives about a slug:
 * `student_profiles.region_id` points here with no foreign key behind it, so a
 * deleted row leaves students living nowhere and a region report with a column it
 * cannot name. `is_active` retires one instead — and a super admin cannot route
 * around it, because `Gate::before` waves them past every policy method and the
 * refusal has to be repeated on the Resource.
 */
class RegionResource extends Resource
{
    protected static ?string $model = Region::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'السوق والتصنيف';

    protected static ?int $navigationSort = 31;

    public static function getNavigationLabel(): string
    {
        return 'المناطق';
    }

    public static function getModelLabel(): string
    {
        return 'منطقة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المناطق';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المنطقة')
                ->description('تظهر في نموذج التسجيل، ويُبنى عليها توزيعُ الطلاب في لوحة التحليلات.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255)
                        ->helperText('ما يقرأه الطالب في قائمة التسجيل. تغييرُه آمن.'),

                    // Immutable once written, exactly as the taxonomy's slug: the
                    // report groups by it and a rename splits one region in two.
                    TextInput::make('slug')
                        ->label('المُعرِّف')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('يُكتب مرّةً ولا يُعدَّل: تقاريرُ التوزيع تجمع به.'),

                    TextInput::make('sort_order')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('مفعَّل')
                        ->default(true)
                        ->helperText('إيقافُه يُخفيه من نموذج التسجيل ولا يمسّ طالباً مسجَّلاً فيه.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                // ⚠️ SORTED BY THE LOCALE'S KEY, NEVER BY THE DOCUMENT. `name` is a
                // translatable JSON column: ordering it raw happens to order by the
                // Arabic value only while every row carries exactly one language.
                TextColumn::make('name')->label('الاسم')->searchable()
                    ->sortable(['name->'.app()->getLocale()]),
                TextColumn::make('slug')->label('المُعرِّف')->searchable(),
                TextColumn::make('sort_order')->label('الترتيب')->sortable(),
                IconColumn::make('is_active')->label('مفعَّل')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مفعَّل'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRegions::route('/'),
            'create' => Pages\CreateRegion::route('/create'),
            'edit' => Pages\EditRegion::route('/{record}/edit'),
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
