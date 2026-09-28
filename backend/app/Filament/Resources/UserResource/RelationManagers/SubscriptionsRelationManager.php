<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLink;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Subscription;
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
 * باقاتُ هذا الحسابِ طالباً — قراءةً فقط.
 *
 * ⚠️ **لا رابطَ للصفّ**: `SubscriptionResource` قائمةٌ بلا صفحةِ سجلّ، والإلغاءُ
 * إجراءٌ على تلك القائمة. الرابطُ الوحيدُ هنا إلى مكانِ العملِ الذي باعها.
 *
 * ⚠️ **وبلا نطاق**، للسببِ نفسِه في {@see OrdersRelationManager}. `plan` يحملُ
 * التجاوزَ بنفسِه (`Subscription::plan()`).
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $modelLabel = 'اشتراك';

    protected static ?string $pluralModelLabel = 'الاشتراكات';

    protected static ?string $title = 'الاشتراكات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedArrowPath;

    /** @return HasMany<Subscription, Model> */
    public function getRelationship(): HasMany
    {
        return $this->getOwnerRecord()
            ->hasMany(Subscription::class, 'student_user_id')
            ->withoutGlobalScope(WorkspaceScope::class);
    }

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
                ->with(['plan', 'workspace']))
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->url(fn (Subscription $record): ?string => RecordLink::to(WorkspaceResource::class, $record->workspace)),
                TextColumn::make('plan.title')->label('الباقة')->placeholder('—'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof SubscriptionStatus ? $state->label() : '—'),
                TextColumn::make('starts_on')->label('من')->date(),
                TextColumn::make('effective_ends_on')->label('إلى')->date(),
            ]);
    }
}
