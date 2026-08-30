<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Modules\Marketplace\Filament\Resources\SchoolYearResource\Pages;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use BackedEnum;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The school years a student picks at registration (spec 022 · FR-011).
 *
 * ⚠️ IT EXTENDS `Resource`, NOT {@see TaxonomyResource}, AND INHERITING WOULD
 * CRASH THE LIST PAGE. That base carries an `icon` field for a column this table
 * does not have, and its `getEloquentQuery()` adds
 * `withCount('teacherProfiles')` — a relation `SchoolYear` does not define — for
 * a column its table renders. Every open of the index would throw.
 * {@see RegionResource} took the same road for the same reason.
 *
 * What IS shared is the decision: {@see TaxonomyPolicy} authorises all four
 * models, so no tenant role reaches any of them.
 *
 * ⚠️ AND NO DELETE. `student_profiles.school_year_slug` and
 * `parent_student_relations.student_school_year_slug` name this row as TEXT with
 * no foreign key behind either, so a deleted row leaves students in a year that
 * cannot be named. `is_active` retires one instead — and a super admin cannot
 * route around it, because `Gate::before` waves them past every policy method
 * and the refusal has to be repeated here, on the Resource.
 */
class SchoolYearResource extends Resource
{
    protected static ?string $model = SchoolYear::class;

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'السوق والتصنيف';

    protected static ?int $navigationSort = 32;

    public static function getNavigationLabel(): string
    {
        return 'الصفوف الدراسية';
    }

    public static function getModelLabel(): string
    {
        return 'صف دراسي';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الصفوف الدراسية';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الصف الدراسي')
                ->description('يختاره الطالب عند التسجيل، وتُشتقّ منه مرحلتُه العريضة.')
                ->columns(2)
                ->schema([
                    TextInput::make('name_ar')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255)
                        ->helperText('ما يقرأه الطالب في قائمة التسجيل. تغييرُه آمن.'),

                    /*
                    | ⚠️ REQUIRED (FR-011أ), AND THE DATABASE SAYS SO TOO.
                    | `grade_level_id` is NOT NULL because a year with no stage
                    | cannot answer the one question every existing reader asks —
                    | the course catalogue, the leaderboard key and the teacher's
                    | settlement rate are all keyed on a broad stage. The rule
                    | lives in both places because the seeder and any importer
                    | reach the model with no form behind them.
                    */
                    Select::make('grade_level_id')
                        ->label('المرحلة العريضة')
                        ->required()
                        ->relationship('gradeLevel', 'name_ar')
                        ->helperText('يُشتقّ منها ما يظهر للمدرّس وما يُبنى عليه سعرُ التسوية.'),

                    // Immutable once written, exactly as the taxonomy's slug and
                    // the region's: `student_profiles` names this row as text.
                    TextInput::make('slug')
                        ->label('المُعرِّف')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('يُكتب مرّةً ولا يُعدَّل: ملفّاتُ الطلاب تحمله نصّاً.'),

                    TextInput::make('sort_order')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('مفعَّل')
                        ->default(true)
                        ->helperText('إيقافُه يُخفيه من نموذج التسجيل ولا يمسّ طالباً مسجَّلاً فيه. وإيقافُ المرحلة يُخفي صفوفَها كلَّها بلا تعديل أيِّ صفّ.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('gradeLevel.name_ar')->label('المرحلة')->sortable(),
                TextColumn::make('slug')->label('المُعرِّف')->searchable(),
                TextColumn::make('sort_order')->label('الترتيب')->sortable(),
                IconColumn::make('is_active')->label('مفعَّل')->boolean(),
            ])
            ->filters([
                SelectFilter::make('grade_level_id')
                    ->label('المرحلة')
                    ->options(fn (): array => GradeLevel::query()
                        ->orderBy('sort_order')
                        ->pluck('name_ar', 'id')
                        ->all()),
                TernaryFilter::make('is_active')->label('مفعَّل'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchoolYears::route('/'),
            'create' => Pages\CreateSchoolYear::route('/create'),
            'edit' => Pages\EditSchoolYear::route('/{record}/edit'),
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
