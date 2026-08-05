<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MessageTemplateResource\Pages;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Permissions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Message wording, editable without a deploy (FR-036).
 *
 * Type and channel are read-only: they are the template's identity, and letting
 * someone repoint a template at another type would silently change what an
 * unrelated notification says.
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $modelLabel = 'قالب رسالة';

    protected static ?string $pluralModelLabel = 'قوالب الرسائل';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::NOTIFICATIONS_TEMPLATES_MANAGE) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('type')->label('نوع الإشعار')->disabled(),
            TextInput::make('channel')->label('القناة')->disabled(),
            TextInput::make('title_ar')->label('العنوان')->required()->maxLength(200),
            Textarea::make('body_ar')
                ->label('النصّ')
                ->required()
                ->rows(4)
                ->helperText('المتغيّرات بالشكل {{ name }}. متغيّر مطلوب وغير مُمرَّر يمنع الإرسال.'),
            Toggle::make('is_active')->label('مفعَّل'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn (string $state): string => NotificationType::from($state)->label())
                    ->searchable(),
                TextColumn::make('channel')
                    ->label('القناة')
                    ->formatStateUsing(fn (string $state): string => NotificationChannel::from($state)->label()),
                TextColumn::make('title_ar')->label('العنوان')->searchable()->limit(40),
                TextColumn::make('provider_approval_status')->label('اعتماد المزوّد')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        MessageTemplate::APPROVAL_APPROVED, MessageTemplate::APPROVAL_NOT_REQUIRED => 'success',
                        MessageTemplate::APPROVAL_PENDING => 'warning',
                        default => 'danger',
                    }),
                IconColumn::make('is_active')->label('مفعَّل')->boolean(),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->label('القناة')->options(
                    collect(NotificationChannel::cases())
                        ->mapWithKeys(fn (NotificationChannel $c): array => [$c->value => $c->label()])
                        ->all(),
                ),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageTemplates::route('/'),
            'edit' => Pages\EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
