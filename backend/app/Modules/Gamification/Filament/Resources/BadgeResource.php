<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Filament\Resources\BadgeResource\Pages;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Badges and the rules that earn them (FR-017).
 *
 * ⚠️ THE RULE TYPE IS A CLOSED LIST, and the threshold is the tunable part. That
 * is deliberately narrower than "configurable rules" sounds: an expression
 * language with no vocabulary is a feature nobody can validate, document or test,
 * while the number — which is what actually needs tuning after a month of real
 * behaviour — moves from this screen.
 *
 * ⚠️ AND CHANGING A RULE NEVER WITHDRAWS A BADGE ALREADY EARNED. What a student
 * earned under the old rule they earned; nothing in the evaluator deletes.
 */
class BadgeResource extends Resource
{
    protected static ?string $model = Badge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'التلعيب';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'الشارات';
    }

    public static function getModelLabel(): string
    {
        return 'شارة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الشارات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تعريف الشارة')
                ->description('المفتاحُ ما تشير إليه الشاراتُ الممنوحة؛ الاسمُ والأيقونةُ ما يراه الطالب.')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->label('المفتاح')
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText('تغييرُه بعد المنح يجعل الشاراتِ الممنوحةَ تشير إلى مفتاحٍ لا وجودَ له، فيظهر المفتاحُ الخام بدل الاسم.'),

                    TextInput::make('name')->label('الاسم')->required()->maxLength(255),
                    TextInput::make('icon')->label('الأيقونة')->maxLength(64),

                    Toggle::make('is_active')->label('مفعَّلة')->default(true),
                ]),

            Section::make('قاعدة الاستحقاق')
                ->description('النوعُ قائمةٌ مغلقة، والعتبةُ هي الجزءُ القابلُ للضبط. تعديلُ القاعدة لا يسحب شارةً مُنحت.')
                ->columns(2)
                ->schema([
                    Select::make('rule_type')
                        ->label('نوع القاعدة')
                        ->required()
                        ->live()
                        ->options(self::ruleTypeOptions()),

                    TextInput::make('rule_value')
                        ->label('العتبة')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Select::make('rule_action_key')
                        ->label('الفعل المعدود')
                        ->options(fn (): array => GamificationAction::query()->pluck('name', 'key')->all())
                        // Only one rule type counts an action; on the others the column is
                        // meaningless and a value left behind would read as a constraint
                        // that is not applied.
                        ->visible(fn ($get): bool => $get('rule_type') === BadgeRuleType::ActionCount->value)
                        ->required(fn ($get): bool => $get('rule_type') === BadgeRuleType::ActionCount->value)
                        ->columnSpanFull()
                        ->helperText('يُحتسب صافياً: القيدُ العكسيُّ يخصم من العدّ.'),
                ]),
        ]);
    }

    /**
     * أسماءُ أنواعِ القواعد بالعربيّة، في موضعٍ واحدٍ تقرأه الاستمارةُ والمرشِّح.
     *
     * @return array<string, string>
     */
    private static function ruleTypeOptions(): array
    {
        return [
            BadgeRuleType::TotalXp->value => 'مجموع الخبرة',
            BadgeRuleType::StreakDays->value => 'أيام السلسلة',
            BadgeRuleType::LevelReached->value => 'بلوغ مستوى',
            BadgeRuleType::ActionCount->value => 'عدد مرّات فعل',
        ];
    }

    /**
     * اسمُ الفعلِ المعدودِ باستعلامٍ فرعيٍّ واحد.
     *
     * ليس `withCount` ولا إغلاقاً داخل عمود: `rule_action_key` نصٌّ لا مفتاحٌ
     * أجنبيّ — فلا علاقةَ تُحمَّل — ومطابقتُه تقع على `gamification_actions.key`
     * وهو فهرسٌ فريد، فالكلفةُ بحثٌ واحدٌ في الفهرس لكلِّ صفٍّ داخل استعلامِ القائمة
     * نفسِه، لا استعلامٌ لكلِّ صفّ.
     *
     * ⚠️ `Builder<Model>` لا `Builder<Badge>`. `Resource` عامٌّ على `TModel`
     * وافتراضُه `Model`، و`parent::getEloquentQuery()` تُرجِعُ ذلك النوعَ نفسَه —
     * فوسمٌ بالنموذجِ الملموسِ هنا يَعِدُ بما لا يُرجِعُه الجسدُ فعلاً.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->addSelect([
            'rule_action_name' => GamificationAction::query()
                // The column is a translatable JSON document and the alias lands
                // on Badge, which has no cast for it — so the locale is extracted
                // in SQL rather than shipping `{"ar":"…"}` to the table cell.
                ->select('name->'.app()->getLocale())
                ->whereColumn('gamification_actions.key', 'badges.rule_action_key')
                ->limit(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('key')->label('المفتاح')->searchable(),
                TextColumn::make('rule_type')->label('القاعدة')->badge()
                    ->formatStateUsing(fn (mixed $state): string => match ($state instanceof BadgeRuleType ? $state : BadgeRuleType::tryFrom((string) $state)) {
                        BadgeRuleType::ActionCount => 'عدد مرّات فعل',
                        BadgeRuleType::TotalXp => 'مجموع الخبرة',
                        BadgeRuleType::StreakDays => 'أيّام متتالية',
                        BadgeRuleType::LevelReached => 'بلوغ مستوى',
                        default => (string) $state,
                    }),
                TextColumn::make('rule_value')->label('العتبة')->sortable(),
                TextColumn::make('rule_action_name')->label('الفعل')->placeholder('—')
                    ->description(fn (Badge $record): ?string => $record->rule_action_key),
                IconColumn::make('is_active')->label('مفعَّلة')->boolean(),
            ])
            ->filters([
                SelectFilter::make('rule_type')->label('نوع القاعدة')->options(self::ruleTypeOptions()),
                TernaryFilter::make('is_active')->label('مفعَّلة'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
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
