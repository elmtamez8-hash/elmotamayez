<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources;

use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Filament\Resources\BreachReportResource\Pages;
use App\Modules\Compliance\Http\Resources\BreachReportResource as BreachReportPayload;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * بلاغاتُ تسرُّبِ البيانات: أين وصلَ التعاملُ معها، وكم بقيَ من مهلةِ الإخطار.
 *
 * ⚠️ الشاشةُ للقراءةِ وحدَها. تقدُّمُ البلاغِ فعلٌ له حرّاسُه — `AdvanceBreachReport`
 * — ومنها القاعدةُ التي لا يعرفُها نموذجُ لوحة: `notified` مرفوضةٌ ما لم يوجدْ
 * ختمَا الإخطارِ كلاهما، ولا يُعادُ ختمُ أحدِهما أبداً. ختمٌ يُعادُ كتابتُه من شاشةٍ
 * ينقلُ واقعةً قانونيّةً إلى اللحظةِ التي لمسَ فيها أحدُهم الصفَّ — أي إلى داخلِ
 * المهلةِ دائماً، مهما تأخّرَ الإخطارُ حقّاً.
 *
 * ⚠️ والموعدانِ مشتقّان، ولا يُشتقّانِ هنا مرّةً ثانية. حمولةُ الوحدةِ نفسِها
 * ({@see BreachReportPayload}) هي الإملاءُ الوحيدُ لهما: `created_at` زائداً نافذةَ
 * الإخطارِ من `ComplianceSettings`، ويسقطُ الموعدُ متى تمَّ الإخطارُ أو أُغلقَ
 * البلاغ. إملاءان لسؤالٍ واحدٍ يفترقان عند أوّلِ يومٍ يقصِّرُ فيه منظِّمٌ المهلة.
 *
 * ⚠️ و`reporter_contact` مخفيٌّ افتراضيّاً: هو ما تركَه إنسانٌ من خارجِ المنصّةِ
 * كي نردَّ عليه، أي بياناتٌ شخصيّةٌ لشخصٍ لم يفتحْ حساباً قطّ.
 *
 * ولا `getEloquentQuery()` هنا: `BreachReport` لا يستعملُ `BelongsToWorkspace` —
 * التسرُّبُ حادثةٌ في المنصّةِ لا في مساحةِ عملٍ واحدة — فلا نطاقَ عامٌّ يُلتَفُّ
 * عليه في الجذر، ولا علاقةَ محمَّلةً مسبقاً يتكرّرُ فيها الالتفاف.
 */
class BreachReportResource extends Resource
{
    protected static ?string $model = BreachReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'الامتثال';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return 'بلاغات التسرُّب';
    }

    public static function getModelLabel(): string
    {
        return 'بلاغ تسرُّب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بلاغات التسرُّب';
    }

    /** بابٌ صريح: افتراضُ Filament هو السماح، والقائمةُ لا تسألُ سياسةَ الصفِّ أبداً. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::COMPLIANCE_BREACHES_MANAGE) ?? false;
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
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (BreachStatus $state): string => $state->label())
                    ->color(fn (BreachStatus $state): string => match ($state) {
                        BreachStatus::Reported => 'danger',
                        BreachStatus::Triaged => 'warning',
                        BreachStatus::Contained => 'info',
                        BreachStatus::Notified => 'info',
                        BreachStatus::Closed => 'success',
                    }),

                TextColumn::make('created_at')
                    ->label('وردَ في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                /*
                | مصدرُ البلاغِ لا صاحبُه: الطابورُ يفرزُ ويفحص، ولا يُعرِّفُ بمن
                | أبلغ. والعمودُ يُقرأُ بـ`getStateUsing` لأنَّ `formatStateUsing`
                | لا تُستدعى أصلاً على حالةٍ فارغة — وهي الحالةُ الأكثرُ وروداً
                | هنا: البلاغُ من خارجِ المنصّةِ هو سببُ وجودِ المسارِ العلنيّ.
                */
                TextColumn::make('reported_by_user_id')
                    ->label('مصدر البلاغ')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (BreachReport $record): string => $record->reported_by_user_id === null
                        ? 'من خارج المنصّة'
                        : 'من حسابٍ مسجَّل'),

                TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(70)
                    ->wrap(),

                TextColumn::make('affected_subject_count')
                    ->label('عدد المعنيّين')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('authority_notified_at')
                    ->label('أُبلغت الجهة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم تُبلَّغ')
                    ->toggleable(),

                TextColumn::make('authority_notice_due_at')
                    ->label('مهلة إبلاغ الجهة')
                    ->getStateUsing(fn (BreachReport $record): ?string => self::derivedDeadline($record, 'authority_notice_due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->color(fn (?string $state): string => self::deadlineColour($state)),

                TextColumn::make('subjects_notified_at')
                    ->label('أُبلغ المعنيّون')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يُبلَّغوا')
                    ->toggleable(),

                TextColumn::make('subjects_notice_due_at')
                    ->label('مهلة إبلاغ المعنيّين')
                    ->getStateUsing(fn (BreachReport $record): ?string => self::derivedDeadline($record, 'subjects_notice_due_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->color(fn (?string $state): string => self::deadlineColour($state)),

                TextColumn::make('reporter_contact')
                    ->label('وسيلة التواصل مع المُبلِّغ')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('closed_at')
                    ->label('أُغلقَ في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(BreachStatus::cases())
                        ->mapWithKeys(fn (BreachStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),

                /*
                | التزامانِ لا التزام: إيقافُ التسرُّبِ شيءٌ وإخبارُ من تسرَّبتْ
                | بياناتُهم شيءٌ آخر. فمُرشِّحانِ منفصلان، لأنَّ المُشغِّلَ يعملُ على
                | واحدٍ منهما في كلِّ مرّة.
                */
                Filter::make('authority_notice_pending')
                    ->label('لم تُبلَّغ الجهة بعد')
                    /**
                     * @param  Builder<BreachReport>  $query
                     */
                    ->query(function (Builder $query): void {
                        $query
                            ->whereNull('authority_notified_at')
                            ->where('status', '!=', BreachStatus::Closed->value);
                    }),

                Filter::make('subjects_notice_pending')
                    ->label('لم يُبلَّغ المعنيّون بعد')
                    /**
                     * @param  Builder<BreachReport>  $query
                     */
                    ->query(function (Builder $query): void {
                        $query
                            ->whereNull('subjects_notified_at')
                            ->where('status', '!=', BreachStatus::Closed->value);
                    }),
            ]);
    }

    /**
     * أحدُ الموعدين، مقروءاً من حمولةِ الوحدةِ بدلَ اشتقاقِه من جديد.
     *
     * لا استعلامَ فيه: البناءُ يقرأُ أعمدةَ الصفِّ الحاضرِ وحدَها، فتكرارُه لكلِّ
     * صفٍّ لا يصنعُ N+1.
     */
    private static function derivedDeadline(BreachReport $record, string $key): ?string
    {
        $payload = (new BreachReportPayload($record))->toArray(request());
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** الموعدُ الذي مضى يُقرأُ أحمر؛ والموعدُ الساقطُ لا لونَ له. */
    private static function deadlineColour(?string $state): string
    {
        return $state !== null && CarbonImmutable::parse($state)->isPast() ? 'danger' : 'gray';
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBreachReports::route('/'),
        ];
    }
}
