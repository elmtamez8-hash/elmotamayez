<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MessageTemplateResource\Pages;
use App\Modules\Notifications\Enums\TemplateApprovalStatus;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'الإشعارات';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'قالب رسالة';

    protected static ?string $pluralModelLabel = 'قوالب الرسائل';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::NOTIFICATIONS_TEMPLATES_MANAGE) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
            | ⚠️ هُويّةُ القالبِ تُقرأُ ولا تُكتَب — ومع ذلك تُعرَضُ بالعربيّة.
            | كانت تُطبَعُ خاماً (`session_report` · `in_app`) في النموذج، بينما
            | الجدولُ في **الشاشةِ نفسِها** يعرضُها من `label()` — إجابتان لسؤالٍ
            | واحدٍ في مورِدٍ واحد، وهي عائلةُ العيبِ الذي وُحِّدَتْ من أجلِه
            | مجموعاتُ القيمِ كلُّها.
            |
            | و`formatStateUsing` لا يعملُ على حقلِ نموذج، فالتحويلُ عندَ التعبئة:
            | `disabled()` يمنعُ الكتابةَ فلا يعودُ النصُّ المعروضُ إلى الجدول.
            */
            TextInput::make('type')
                ->label('نوع الإشعار')
                ->disabled()
                ->formatStateUsing(fn (?string $state): string => $state === null
                    ? '—'
                    : (NotificationType::tryFrom($state)?->label() ?? $state)),
            TextInput::make('channel')
                ->label('القناة')
                ->disabled()
                ->formatStateUsing(fn (?string $state): string => $state === null
                    ? '—'
                    : (NotificationChannel::tryFrom($state)?->label() ?? $state)),
            TextInput::make('title_ar')->label('العنوان')->required()->maxLength(200),
            Textarea::make('body_ar')
                ->label('النصّ')
                ->required()
                ->rows(4)
                ->helperText('المتغيّرات بالشكل {{ name }}. متغيّر مطلوب وغير مُمرَّر يمنع الإرسال.'),
            /*
            | Spec 020 — the outcome of a process that happens outside this system.
            |
            | ⚠️ IT WAS A TABLE COLUMN AND NOT A FORM FIELD, so the state was
            | visible and unreachable: WhatsApp rows ship `pending`, the renderer
            | refuses a template that is not approved, and there was no way in the
            | product to record an approval once the provider granted it. The
            | deployment checklist described a control that did not exist.
            |
            | Editable rather than derived because nothing here can observe it —
            | approval is a human decision at the provider, and this field is how
            | the system is told.
            */
            Select::make('provider_approval_status')
                ->label('اعتماد المزوّد')
                ->options(TemplateApprovalStatus::options())
                ->required()
                ->helperText('قنوات مثل واتساب ترفض قالباً غير معتمَد. اقلبه إلى «معتمَد» بعد موافقة المزوّد وليس قبلها.'),
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
                    ->formatStateUsing(fn (string $state): string => TemplateApprovalStatus::labelFor($state))
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
