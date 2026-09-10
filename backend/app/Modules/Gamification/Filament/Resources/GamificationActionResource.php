<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Modules\Gamification\Filament\Resources\GamificationActionResource\Pages;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * What every action is worth (FR-002 · Q4).
 *
 * The initial values are explicitly provisional and get tuned after a month of
 * real behaviour — which is the entire reason they are rows an operator edits
 * rather than constants in a file.
 *
 * ⚠️ NO TENANT ROLE REACHES THIS SCREEN, THE WORKSPACE OWNER INCLUDED. A teacher
 * who could raise "attended a session" from 10 to 500 would seat their own
 * students at the top of the subject, the grade and the platform boards, and
 * everyone else's below them. Authorisation is {@see CataloguePolicy}, so this
 * screen and any future endpoint answer with the same code.
 *
 * ⚠️ CHANGES APPLY FROM THEN ON AND NEVER BACKWARDS (FR-003). Each award froze
 * what was actually applied, so nothing here can rewrite a student's history —
 * and nothing here should try.
 *
 * ⚠️ DISABLE, NEVER DELETE. Every award entry names its action by KEY; deleting
 * the row would leave those entries pointing at nothing.
 */
class GamificationActionResource extends Resource
{
    protected static ?string $model = GamificationAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'التلعيب';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'الأفعال';
    }

    public static function getModelLabel(): string
    {
        return 'فعل تلعيب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أفعال التلعيب';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تعريف الفعل')
                ->description('المفتاحُ هو ما يرسله المستمع، والاسمُ هو ما يقرؤه الطالبُ في سجلّه.')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->label('المفتاح')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText('يُطابق ما يرسله المستمع. تغييرُه يفصل الفعلَ عن مصدره فيتوقّف المنح بصمت.'),

                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255),

                    Toggle::make('is_active')
                        ->label('مفعَّل')
                        ->default(true)
                        ->helperText('إيقافُه يمنع المنحَ التالي ولا يمسّ منحاً سابقاً.'),
                ]),

            Section::make('ما يستحقّه الفعل')
                ->description('القيمُ مؤقّتةٌ بطبيعتها وتُضبَط بعد شهرٍ من السلوك الفعليّ، ولا تسري إلّا على ما يأتي بعدها.')
                ->columns(2)
                ->schema([
                    TextInput::make('xp')
                        ->label('الخبرة')
                        ->numeric()
                        ->required()
                        ->helperText('يجوز أن تكون سالبة: العقوبة صفٌّ هنا لا فرعٌ في الكود.'),

                    TextInput::make('coins')
                        ->label('العملات')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->helperText('العملاتُ مقسّمةٌ بالمدرّس، ففعلٌ يقع خارج أي مساحةِ عمل (‏جلسة تركيز) يجب أن يكون صفراً.'),

                    TextInput::make('daily_cap')
                        ->label('السقف اليومي')
                        ->numeric()
                        ->minValue(1)
                        ->columnSpanFull()
                        ->helperText('اتركه فارغاً فلا سقف. السقفُ يوقف المنحَ فوقه ولا يُفشل الفعلَ نفسَه.'),
                ]),
        ]);
    }

    /**
     * عددُ الشاراتِ التي تَعُدُّ هذا الفعل — تحذيرٌ قبل التعطيل.
     *
     * استعلامٌ فرعيٌّ واحدٌ داخل استعلامِ القائمة، لا `withCount` ولا إغلاقٌ داخل
     * عمود: `badges.rule_action_key` نصٌّ لا مفتاحٌ أجنبيّ فلا علاقةَ هناك تُعَدّ،
     * وجدولُ الشاراتِ بياناتٌ مرجعيّةٌ من عشراتِ الصفوف.
     *
     * ⚠️ `Builder<Model>` لا `Builder<GamificationAction>`. `Resource` عامٌّ على `TModel`
     * وافتراضُه `Model`، و`parent::getEloquentQuery()` تُرجِعُ ذلك النوعَ نفسَه —
     * فوسمٌ بالنموذجِ الملموسِ هنا يَعِدُ بما لا يُرجِعُه الجسدُ فعلاً.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->addSelect([
            'badges_count' => Badge::query()
                ->selectRaw('count(*)')
                ->whereColumn('badges.rule_action_key', 'gamification_actions.key'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                // ⚠️ SORTED BY THE LOCALE'S KEY, NEVER BY THE DOCUMENT. `name` is a
                // translatable JSON column: ordering it raw happens to order by the
                // Arabic value only while every row carries exactly one language.
                TextColumn::make('name')->label('الاسم')->searchable()
                    ->sortable(['name->'.app()->getLocale()]),
                TextColumn::make('key')->label('المفتاح')->searchable(),
                TextColumn::make('xp')->label('الخبرة')->sortable()->badge()
                    // القيمةُ السالبةُ عقوبة، وقراءتُها كرقمٍ عاديٍّ بين الأرقام هي
                    // كيف يمرّ فعلٌ يخصم على أنّه فعلٌ يمنح.
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'gray'),
                TextColumn::make('coins')->label('العملات')->sortable(),
                TextColumn::make('daily_cap')->label('السقف اليومي')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'بلا سقف' : (string) $state),
                TextColumn::make('badges_count')->label('شارات تعتمد عليه')->sortable()
                    ->tooltip('تعطيلُ الفعل يوقف تقدّمَ هذه الشارات، ولا يسحب ما مُنح منها.'),
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
            'index' => Pages\ListGamificationActions::route('/'),
            'create' => Pages\CreateGamificationAction::route('/create'),
            'edit' => Pages\EditGamificationAction::route('/{record}/edit'),
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
