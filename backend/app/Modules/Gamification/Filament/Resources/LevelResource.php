<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources;

use App\Modules\Gamification\Filament\Resources\LevelResource\Pages;
use App\Modules\Gamification\Models\Level;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function getNavigationLabel(): string
    {
        return 'مستويات التلعيب';
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
            TextInput::make('level')
                ->label('الرقم')
                ->numeric()
                ->required()
                ->minValue(1)
                ->unique(ignoreRecord: true),

            TextInput::make('name_ar')
                ->label('الاسم')
                ->required()
                ->maxLength(255),

            TextInput::make('xp_threshold')
                ->label('عتبة الخبرة')
                ->numeric()
                ->required()
                ->minValue(0)
                ->helperText('أقلُّ خبرةٍ تضع الطالبَ في هذا المستوى. رفعُها لا يُنزل أحداً بلغه سلفاً.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('xp_threshold')
            ->columns([
                TextColumn::make('level')->label('الرقم')->sortable(),
                TextColumn::make('name_ar')->label('الاسم')->searchable(),
                TextColumn::make('xp_threshold')->label('عتبة الخبرة')->sortable(),
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
