<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Models\User;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\RejectTeacherApplication;
use App\Modules\Marketplace\Actions\RequestApplicationChanges;
use App\Modules\Marketplace\Enums\TeacherApplicationStatus;
use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource\Pages;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'السوق والتصنيف';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function getNavigationLabel(): string
    {
        return 'طلبات المدرّسين';
    }

    /*
    | عدّادُ صندوقِ الوارد. هذه الشاشةُ هي الوحيدةُ في اللوحةِ التي تنتظرُ قراراً
    | من إنسان — وطلبٌ مُرسَلٌ لا يراه أحدٌ هو مدرّسٌ ينتظرُ بلا جواب.
    |
    | ⚠️ استعلامٌ واحدٌ على كلِّ صفحةٍ في اللوحة، لذا لا ثانيَ له: العدّادُ يُوضَعُ
    | حيثُ يوجدُ عملٌ متوقّف، لا على كلِّ مورِد.
    */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()
            ->whereIn('status', [
                TeacherApplication::STATUS_SUBMITTED,
                TeacherApplication::STATUS_CHANGES_REQUESTED,
            ])
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getModelLabel(): string
    {
        return 'طلب انضمام';
    }

    /*
    | ⚠️ العنوانُ في أعلى الشاشةِ يُقرأُ من الاسمِ الجمعيِّ لا من اسمِ التنقّل،
    | فغيابُه كان يطبعُ «Teacher Applications» فوقَ جدولٍ عربيٍّ بالكامل.
    */
    public static function getPluralModelLabel(): string
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
                TextColumn::make('user.name')->label('المتقدّم')->searchable(['first_name', 'last_name']),
                TextColumn::make('user.email')->label('البريد')->searchable()->copyable(),

                /*
                | مساحةُ العملِ عمودٌ لا زينةً: الطابورُ منصّيٌّ يقرأُ طلباتِ كلِّ
                | المساحات، وبلا هذا العمودِ يقفُ اسمان متشابهان من مساحتَين
                | مختلفتَين في صفَّين متجاورَين بلا ما يفرِّقُهما.
                */
                TextColumn::make('workspace.name')->label('مساحة العمل')->searchable()->toggleable(),

                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => TeacherApplicationStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        TeacherApplication::STATUS_APPROVED => 'success',
                        TeacherApplication::STATUS_SUBMITTED => 'info',
                        TeacherApplication::STATUS_CHANGES_REQUESTED => 'warning',
                        TeacherApplication::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                // «٢ من ٤»: رقمٌ عارٍ لا يقولُ كم بقي، والمُسوَّدةُ الواقفةُ عندَ
                // خطوةٍ بعينِها هي أكثرُ ما يُقرأُ في هذا العمود.
                TextColumn::make('current_step')->label('الخطوة')->badge()->color('gray')
                    ->formatStateUsing(fn (int $state): string => $state.' من '.TeacherApplication::LAST_STEP),

                /*
                | ما صارَ إليه القرار: القبولُ يقلبُ `approval_status` على ملفِّ
                | المدرّس، وبلا هذا العمودِ لا يظهرُ في الطابورِ أثرُ القرارِ
                | إطلاقاً. تفصيلُ الملفِّ في {@see TeacherProfileResource}.
                */
                TextColumn::make('teacherProfile.approval_status')->label('حالة الملفّ')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : TeacherProfileResource::approvalLabel($state))
                    ->color(fn (?string $state): string => $state === null
                        ? 'gray'
                        : TeacherProfileResource::approvalColor($state))
                    ->toggleable(),

                TextColumn::make('submitted_at')->label('أُرسل')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),

                TextColumn::make('reviewed_at')->label('رُوجع')->dateTime('Y-m-d H:i')->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    /*
                    | ⚠️ المجموعةُ ومقابلُها العربيُّ يعيشان في النوعِ لا هنا: كانتا
                    | اثنتَين — المرشِّحُ عربيٌّ والشارةُ تطبعُ `changes_requested`
                    | خاماً في العمودِ المجاور. والمسوّدةُ وحدَها تُستثنى: طلبٌ لم
                    | يُرسَلْ بعدُ ليس قراراً ينتظرُ أحداً.
                    */
                    ->options(collect(TeacherApplicationStatus::options())
                        ->except(TeacherApplication::STATUS_DRAFT)
                        ->all()),

                // للعثورِ على المُسوَّداتِ الواقفة: أينَ يتوقّفُ المتقدّمون فعلاً.
                SelectFilter::make('current_step')
                    ->label('الخطوة')
                    ->options([
                        1 => 'الخطوة ١',
                        2 => 'الخطوة ٢',
                        3 => 'الخطوة ٣',
                        4 => 'الخطوة ٤',
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

    /**
     * ⚠️ طابورٌ منصّيٌّ يُصرِّحُ بتخطّي نطاقِ مساحةِ العملِ صراحةً — وبدونِه كان
     * يعرضُ لا شيء. الطلبُ يحملُ `workspace_id` وهو مساحةُ المتقدِّم،
     * و`WorkspaceContext::id()` يرتدُّ إلى `last_workspace_id` حتّى للمشرِفِ
     * العامّ: فالنطاقُ يقارنُ مساحةَ المراجِعِ بمساحةِ كلِّ متقدِّمٍ ولا يطابقُ
     * أحداً، فتقولُ الشاشةُ «لا طلبات» فوقَ طلباتٍ تنتظرُ قراراً — وتختفي معها
     * شارةُ العدّاد، فلا شيءَ يقولُ إنّ هناك عملاً متوقّفاً.
     * و`marketplace.teachers.review` لا يحملُه أيُّ دورِ مساحةٍ أصلاً، فالتخطّي
     * لا يوسّعُ ما يراه أحد.
     *
     * ⚠️ والتخطّي لكلِّ نموذجٍ على حدة: `teacherProfile` يُحمَّلُ باستعلامٍ خاصٍّ
     * تُطبَّقُ فيه نطاقاتُ {@see TeacherProfile} نفسِه، فبلا التخطّي داخلَ
     * الإغلاقِ يعودُ العمودُ فارغاً لكلِّ صفٍّ خارجَ مساحةِ المراجِع — القائمةُ
     * تبدو مُصلَحةً والعمودُ وحدَه يحملُ العطل.
     *
     * `user` و`workspace` بلا تخطٍّ: لا واحدَ منهما منطاق. وبلا تقييدِ أعمدةٍ
     * كذلك — `users` لا تحملُ عمودَ `name`، وإنّما هو مُلحِقٌ فوقَ الاسمَين،
     * فقائمةٌ مقيَّدةٌ تُفرِغُ كلَّ اسمٍ في الجدولِ بصمت.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->with([
                'user',
                'workspace',
                'teacherProfile' => fn ($profile) => $profile->withoutGlobalScope(WorkspaceScope::class),
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
