<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\RelationManagers;

use App\Modules\Marketplace\Actions\ConfirmComplaint;
use App\Modules\Marketplace\Actions\DismissComplaint;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * الشكاوى المرفوعةُ على هذا المدرّس — للقراءةِ فقط.
 *
 * ⚠️ لا تأكيدَ ولا صرفَ من هنا. القراران يمرّان بـ {@see ConfirmComplaint} و
 * {@see DismissComplaint}، وهما ما يُطلِقُ الحدثَ الذي تُخصَمُ عليه درجةُ الثقة.
 * كتابةُ `status` مباشرةً تُغلِقُ الشكوى في الجدولِ ولا تُنقِصُ المدرّسَ نقطةً
 * واحدة — ولا تكتبُ `confirmed_at`، وهو وحدَه ما يُميّزُ شكوى ثبتَت من شكوى
 * قُدِّمَت.
 */
class ComplaintsRelationManager extends RelationManager
{
    protected static string $relationship = 'complaints';

    protected static ?string $title = 'الشكاوى';

    /*
    | قائمةٌ واحدةٌ للشارةِ وللمرشِّح؛ قائمتان تطبعُ إحداهما `dismissed` خاماً في
    | العمودِ المجاورِ للأخرى.
    */
    private const STATUSES = [
        Complaint::STATUS_OPEN => 'مفتوحة',
        Complaint::STATUS_CONFIRMED => 'ثبتَت',
        Complaint::STATUS_DISMISSED => 'صُرفت',
    ];

    /**
     * `marketplace.complaints.manage` لا إذنَ مراجعةِ المدرّسين، و`Complaint`
     * ليس لها سياسةٌ أصلاً فلا شيءَ يُسأَلُ افتراضيّاً.
     *
     * الفرقُ اسمُ المُبلِّغ: عدّادُ «شكاوى مفتوحة» في القائمةِ رقمٌ لا يدلُّ على
     * أحد، وهذا التبويبُ يحملُ مَن رفعَها ونصَّ ما قال. اليومَ يجتمعُ الإذنان في
     * المشرِفِ العامِّ نفسِه فلا يُخفي هذا الفصلُ شيئاً؛ يومَ يُنشَأُ دورُ مراجعةٍ
     * أكاديميّةٌ منفصلٌ يكونُ الفصلُ قد كُتِبَ قبلَ أن يُحتاجَ إليه.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_COMPLAINTS_MANAGE) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            /*
            | `Complaint` تحملُ `workspace_id`: التخطّي لكلِّ نموذجٍ على حدة، وإلّا
            | قرأَ المشرِفُ «لا شكاوى» على مدرّسٍ من مساحةٍ غيرِ مساحتِه المرتدّة.
            | و`with('reporter')` بلا تقييدِ أعمدة: `users` لا تحملُ `name`.
            */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->with('reporter'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reporter.name')->label('المُبلِّغ')->placeholder('—'),

                TextColumn::make('reason')
                    ->label('السبب')
                    ->limit(80)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Complaint::STATUS_CONFIRMED => 'danger',
                        Complaint::STATUS_DISMISSED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('confirmed_at')
                    ->label('ثبتَت في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')->label('رُفعت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(self::STATUSES),
            ]);
    }
}
