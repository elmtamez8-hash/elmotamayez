<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Models\User;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\RejectTeacherApplication;
use App\Modules\Marketplace\Actions\RequestApplicationChanges;
use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource\Pages;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Tenancy\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The academic team's review queue (FR-016).
 *
 * Every button calls the same Action the API calls (Constitution II). Nothing
 * here writes approval_status or is_publicly_listed directly — a reviewer
 * clicking "approve" in Filament must produce exactly what the endpoint produces,
 * including the cache flush and the notification.
 */
class TeacherApplicationResource extends Resource
{
    protected static ?string $model = TeacherApplication::class;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationLabel(): string
    {
        return 'طلبات المدرّسين';
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_REVIEW) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('المتقدّم')->searchable(),
                TextColumn::make('user.email')->label('البريد')->searchable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        TeacherApplication::STATUS_APPROVED => 'success',
                        TeacherApplication::STATUS_SUBMITTED => 'info',
                        TeacherApplication::STATUS_CHANGES_REQUESTED => 'warning',
                        TeacherApplication::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('current_step')->label('الخطوة'),
                TextColumn::make('submitted_at')->label('أُرسل')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    TeacherApplication::STATUS_SUBMITTED => 'قيد المراجعة',
                    TeacherApplication::STATUS_CHANGES_REQUESTED => 'بانتظار تعديل',
                    TeacherApplication::STATUS_APPROVED => 'مقبول',
                    TeacherApplication::STATUS_REJECTED => 'مرفوض',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('قبول')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (TeacherApplication $record): bool => self::isPending($record) && self::canDecide())
                    ->action(fn (TeacherApplication $record) => app(ApproveTeacherApplication::class)
                        ->handle($record, self::reviewer())),

                Action::make('requestChanges')
                    ->label('طلب تعديل')
                    ->color('warning')
                    ->schema([Textarea::make('reason')->label('المطلوب')->required()->maxLength(1000)])
                    ->visible(fn (TeacherApplication $record): bool => self::isPending($record) && self::canDecide())
                    ->action(fn (TeacherApplication $record, array $data) => app(RequestApplicationChanges::class)
                        ->handle($record, self::reviewer(), (string) $data['reason'])),

                Action::make('reject')
                    ->label('رفض')
                    ->color('danger')
                    // Required, not optional: a rejection the applicant cannot act
                    // on is a dead end (FR-016).
                    ->schema([Textarea::make('reason')->label('سبب الرفض')->required()->maxLength(1000)])
                    ->visible(fn (TeacherApplication $record): bool => self::isPending($record) && self::canDecide())
                    ->action(fn (TeacherApplication $record, array $data) => app(RejectTeacherApplication::class)
                        ->handle($record, self::reviewer(), (string) $data['reason'])),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherApplications::route('/'),
        ];
    }

    private static function isPending(TeacherApplication $application): bool
    {
        return in_array($application->status, [
            TeacherApplication::STATUS_SUBMITTED,
            TeacherApplication::STATUS_CHANGES_REQUESTED,
        ], true);
    }

    private static function canDecide(): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_APPROVE) ?? false;
    }

    private static function reviewer(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
