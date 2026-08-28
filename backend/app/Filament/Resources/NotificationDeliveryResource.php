<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationDeliveryResource\Pages;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What was sent, on which channel, and why anything failed (FR-038).
 *
 * Read-only, and gated by notifications.logs.view (FR-039) — the log names every
 * recipient on the platform. It shows no phone number or address because the
 * table has no column for one: the channel reads the destination off the user at
 * send time and never copies it here (FR-040).
 */
class NotificationDeliveryResource extends Resource
{
    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'الإشعارات';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'محاولة تسليم';

    protected static ?string $pluralModelLabel = 'سجلّ التسليم';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::NOTIFICATIONS_LOGS_VIEW) ?? false;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notification.recipient.email')->label('المستلم')->searchable(),
                TextColumn::make('notification.type')
                    ->label('النوع')
                    ->formatStateUsing(fn (string $state): string => NotificationType::from($state)->label()),
                TextColumn::make('channel')
                    ->label('القناة')
                    ->formatStateUsing(fn (string $state): string => NotificationChannel::from($state)->label()),
                TextColumn::make('template.key')->label('القالب')->toggleable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DeliveryStatus::from($state)->label())
                    ->color(fn (string $state): string => match ($state) {
                        DeliveryStatus::Delivered->value => 'success',
                        DeliveryStatus::Failed->value => 'danger',
                        DeliveryStatus::Skipped->value => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('attempts')->label('المحاولات')->sortable(),
                TextColumn::make('failure_reason')->label('السبب')->limit(60)->toggleable(),
                TextColumn::make('deferred_until')->label('مؤجَّل حتى')->dateTime()->toggleable(),
                TextColumn::make('last_attempted_at')->label('آخر محاولة')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(DeliveryStatus::cases())
                        ->mapWithKeys(fn (DeliveryStatus $s): array => [$s->value => $s->label()])
                        ->all(),
                ),
                SelectFilter::make('channel')->label('القناة')->options(
                    collect(NotificationChannel::cases())
                        ->mapWithKeys(fn (NotificationChannel $c): array => [$c->value => $c->label()])
                        ->all(),
                ),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationDeliveries::route('/'),
        ];
    }
}
