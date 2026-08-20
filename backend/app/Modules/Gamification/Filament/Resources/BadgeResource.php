<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Modules\Gamification\Enums\BadgeRuleType;
use App\Modules\Gamification\Filament\Resources\BadgeResource\Pages;
use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\GamificationAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function getNavigationLabel(): string
    {
        return 'شارات التلعيب';
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
            TextInput::make('key')
                ->label('المفتاح')
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true)
                ->helperText('تغييرُه بعد المنح يجعل الشاراتِ الممنوحةَ تشير إلى مفتاحٍ لا وجودَ له، فيظهر المفتاحُ الخام بدل الاسم.'),

            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(255),
            TextInput::make('icon')->label('الأيقونة')->maxLength(64),

            Select::make('rule_type')
                ->label('نوع القاعدة')
                ->required()
                ->live()
                ->options([
                    BadgeRuleType::TotalXp->value => 'مجموع الخبرة',
                    BadgeRuleType::StreakDays->value => 'أيام السلسلة',
                    BadgeRuleType::LevelReached->value => 'بلوغ مستوى',
                    BadgeRuleType::ActionCount->value => 'عدد مرّات فعل',
                ]),

            TextInput::make('rule_value')
                ->label('العتبة')
                ->numeric()
                ->required()
                ->minValue(1),

            Select::make('rule_action_key')
                ->label('الفعل المعدود')
                ->options(fn (): array => GamificationAction::query()->pluck('name_ar', 'key')->all())
                // Only one rule type counts an action; on the others the column is
                // meaningless and a value left behind would read as a constraint
                // that is not applied.
                ->visible(fn ($get): bool => $get('rule_type') === BadgeRuleType::ActionCount->value)
                ->required(fn ($get): bool => $get('rule_type') === BadgeRuleType::ActionCount->value)
                ->helperText('يُحتسب صافياً: القيدُ العكسيُّ يخصم من العدّ.'),

            Toggle::make('is_active')->label('مفعَّلة')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('key')->label('المفتاح')->searchable(),
                TextColumn::make('rule_type')->label('القاعدة')->badge(),
                TextColumn::make('rule_value')->label('العتبة')->sortable(),
                TextColumn::make('rule_action_key')->label('الفعل'),
                IconColumn::make('is_active')->label('مفعَّلة')->boolean(),
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
