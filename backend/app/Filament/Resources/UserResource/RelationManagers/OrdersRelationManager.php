<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLink;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Models\Order;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * طلباتُ هذا الحسابِ في كلِّ مكانِ عمل — قراءةً فقط، وكلُّ صفٍّ يفتحُ طلبَه.
 *
 * ⚠️ **العلاقةُ تُبنى هنا لا على `User`.** `User` مشتركٌ بين الوحداتِ كلِّها،
 * وعلاقةٌ عليه إلى `Payments` تفتحُ لكلِّ وحدةٍ طريقاً إلى الطلباتِ لأجلِ شاشةٍ
 * واحدة. اللوحةُ خارجَ الوحدات، فالربطُ مكانُه هنا.
 *
 * ⚠️ **وبلا نطاقٍ على الطلبِ وعلى الكورسِ المُحمَّلِ معه.** الطالبُ يشتري من
 * مدرّسين كثيرين، وسياقُ مديرِ المنصّةِ يرجعُ إلى `users.last_workspace_id` —
 * فقائمةٌ منطاقةٌ تعرضُ طلباتِ مكانٍ واحدٍ على أنّها كلُّ ما اشتراه.
 * (`Order::course()` تحملُ التجاوزَ بنفسِها.)
 *
 * ولا إجراءَ هنا: الاعتمادُ والرفضُ والعكسُ على شاشةِ الطلبِ وحدَها.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    protected static ?string $title = 'الطلبات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedShoppingCart;

    /** @return HasMany<Order, Model> */
    public function getRelationship(): HasMany
    {
        return $this->getOwnerRecord()
            ->hasMany(Order::class, 'user_id')
            ->withoutGlobalScope(WorkspaceScope::class);
    }

    /** صفحةُ الحسابِ لمديرِ المنصّةِ وحدَه ({@see UserResource::canViewAny()}). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->with(['course', 'workspace']))
            ->columns([
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->placeholder('—')
                    ->wrap()
                    ->url(fn (Order $record): ?string => RecordLink::to(CourseResource::class, $record->course)),
                TextColumn::make('workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->url(fn (Order $record): ?string => RecordLink::to(WorkspaceResource::class, $record->workspace)),
                TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof OrderKind ? $state->label() : '—'),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money(fn (Order $record): string => $record->currency),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OrderStatus::labelFor($state)),
                TextColumn::make('created_at')->label('تاريخ الطلب')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordUrl(fn (Order $record): ?string => RecordLink::to(OrderResource::class, $record));
    }
}
