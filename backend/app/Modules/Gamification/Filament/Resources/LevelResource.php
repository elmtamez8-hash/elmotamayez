<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Filament\Resources\LevelResource\Pages;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\Level;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The experience ladder (FR-012), tunable without a deploy.
 *
 * ⚠️ RAISING A THRESHOLD DOES NOT DEMOTE ANYBODY. The stored level only ever
 * moves up (`WHERE level < :n`), so an operator who makes level 3 harder affects
 * who reaches it next — not who is already there. Lowering one promotes on the
 * next award, which is the intended direction.
 *
 * Platform reference data: no tenant role reaches this screen.
 */
class LevelResource extends Resource
{
    protected static ?string $model = Level::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'التلعيب';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'المستويات';
    }

    public static function getModelLabel(): string
    {
        return 'مستوى';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المستويات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('درجة السلّم')
                ->description('الرقمُ ترتيبُ الدرجة، والعتبةُ أقلُّ خبرةٍ تضع الطالبَ فيها. السلّمُ يُقرأ نزولاً من العتبة.')
                ->columns(2)
                ->schema([
                    TextInput::make('level')
                        ->label('الرقم')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->unique(ignoreRecord: true),

                    TextInput::make('name')
                        ->label('الاسم')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('xp_threshold')
                        ->label('عتبة الخبرة')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->columnSpanFull()
                        ->helperText('أقلُّ خبرةٍ تضع الطالبَ في هذا المستوى. رفعُها لا يُنزل أحداً بلغه سلفاً.'),
                ]),
        ]);
    }

    /**
     * عددُ الشاراتِ المشروطةِ ببلوغِ هذه الدرجة.
     *
     * الرقمُ هو أثرُ تعديلِ العتبةِ الذي لا يظهر في هذا الجدول وحده: رفعُ العتبةِ
     * يؤخّر هذه الشاراتِ لمن لم يبلغها بعد. استعلامٌ فرعيٌّ واحدٌ لا `withCount`،
     * إذ لا مفتاحَ أجنبيَّ بين الجدولين — الشرطُ رقمُ الدرجةِ في `rule_value`.
     *
     * ⚠️ `Builder<Model>` لا `Builder<Level>`. `Resource` عامٌّ على `TModel`
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
                ->where('badges.rule_type', BadgeRuleType::LevelReached->value)
                ->whereColumn('badges.rule_value', 'levels.level'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('xp_threshold')
            ->columns([
                TextColumn::make('level')->label('الرقم')->sortable(),
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('xp_threshold')->label('عتبة الخبرة')->sortable(),
                TextColumn::make('badges_count')->label('شارات تُمنح عنده')->sortable()
                    ->tooltip('رفعُ العتبةِ يؤخّر هذه الشارات لمن لم يبلغ الدرجة، ولا يسحب ما مُنح منها.'),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLevels::route('/'),
            'create' => Pages\CreateLevel::route('/create'),
            'edit' => Pages\EditLevel::route('/{record}/edit'),
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
}
