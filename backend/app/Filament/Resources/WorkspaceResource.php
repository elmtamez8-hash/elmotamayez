<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WorkspaceResource\Pages;
use App\Modules\Tenancy\Enums\WorkspaceType;
use App\Modules\Tenancy\Models\Workspace;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * مساحاتُ العمل — كلُّ مساحةٍ مدرّسٌ (أو أكاديميّة). **قراءةً فقط**، لمدير المنصّة.
 *
 * ⚠️ لا إنشاءَ من هنا: `WorkspaceCreated` هو ما يُطلِقُ `SeedDefaultRoles`، ومساحةٌ
 * تُكتَبُ بإدراجٍ مباشرٍ تُولَدُ بلا دورٍ واحد — فلا مالكَ لها يستطيعُ فعلَ شيءٍ
 * فيها، ولا شيءَ في الشاشةِ يقولُ لماذا. ولا حذفَ: الصفُّ مشارٌ إليه من كلِّ جدولٍ
 * مقسَّمٍ بـ`workspace_id` في المنتَج.
 *
 * ⚠️ والبابُ مُعلَنٌ صراحةً — قائمةُ Filament لا تستدعي سياسةَ الصفِّ أبداً.
 */
class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'المنصّة';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'مساحات العمل';
    }

    public static function getModelLabel(): string
    {
        return 'مساحة عمل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مساحات العمل';
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * ⚠️ العدّانِ استعلامٌ واحدٌ لكلِّ صفحة، لا استعلامٌ لكلِّ صفّ. مورِدُ Filament
     * يُنفَّذُ مرّةً لكلِّ صفّ، فأيُّ `->count()` داخلَ عمودٍ هو N+1 بالتعريف.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['members']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('slug')->label('المُعرِّف')->searchable()->toggleable(),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state): string => WorkspaceType::labelFor($state)),
                TextColumn::make('owner.email')
                    ->label('المالك')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('members_count')
                    ->label('الأعضاء')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('أُنشئت في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkspaces::route('/'),
        ];
    }
}
