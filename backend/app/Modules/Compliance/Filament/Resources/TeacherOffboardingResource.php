<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources;

use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Filament\Resources\TeacherOffboardingResource\Pages;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
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
 * خروجُ المدرّسين: أين وصلَ كلُّ طلبٍ، ومتى تنتهي مهلةُ الإخطار.
 *
 * ⚠️ الشاشةُ للقراءةِ وحدَها، ولا زرَّ «أكمِل» فيها. الإكمالُ فعلٌ له حرّاسُه
 * (`ExecuteTeacherOffboarding`) وله تحديثٌ شرطيٌّ واحدٌ يمنعُ مُشغِّلَينِ من المرورِ
 * معاً — وآثارُه لا تُعكَس: عضويّاتٌ تنتهي، وصلاحيّاتُ مساعدٍ تُسحَب، ورموزُ دخولٍ
 * تُقتَل. ونموذجُ لوحةٍ يكتبُ هذه الأعمدةَ مباشرةً هو طريقٌ حولَ الشرطِ لا حولَ
 * الزرِّ فقط.
 *
 * ⚠️ ولا رقمَ مالَ هنا، ولا قراءةَ للعمودِ الذي يحملُ خلوَّ الذمّة. الجوابُ يُطلَبُ
 * من النموذجِ عبرَ `duesCleared()`: حدُّ السياقاتِ عندَ طرفِ الحمولة، وشاشةٌ تتحدّثُ
 * لغةَ الحسابِ الماليِّ تبعدُ حقلاً واحداً عن حملِ رقمٍ منه — وهو ما يُسقِطُ البناءَ
 * في `ContextIsolationTest` عن حقٍّ.
 *
 * ⚠️ ومهلةُ الإخطارُ شرطٌ ثانٍ مستقلٌّ عن خلوِّ الذمّة، لا وجهٌ آخرُ له. الشاشةُ
 * تعرضُ الاثنين معاً لأنَّ الطلبَ المرفوضَ بأحدِهما يبدو — إن عُرضَ الآخرُ وحدَه —
 * رفضاً بلا سبب.
 */
class TeacherOffboardingResource extends Resource
{
    protected static ?string $model = TeacherOffboarding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowLeftOnRectangle;

    protected static string|UnitEnum|null $navigationGroup = 'الامتثال';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return 'خروج المدرّسين';
    }

    public static function getModelLabel(): string
    {
        return 'طلب خروج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات الخروج';
    }

    /** بابٌ صريح: افتراضُ Filament هو السماح، والقائمةُ لا تسألُ سياسةَ الصفِّ أبداً. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::COMPLIANCE_OFFBOARDING_EXECUTE) ?? false;
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
                // مفتاحٌ حقيقيٌّ ليجدَ البحثُ ما يقرأ، والمعروضُ الاسمُ الكامل:
                // `users` لا تحملُ عمودَ `name`.
                TextColumn::make('teacher.first_name')
                    ->label('المدرّس')
                    ->formatStateUsing(fn (TeacherOffboarding $record): string => $record->teacher->name ?? '—')
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('workspace.name')
                    ->label('مساحة العمل')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (OffboardingStatus $state): string => $state->label())
                    ->color(fn (OffboardingStatus $state): string => match ($state) {
                        OffboardingStatus::Requested => 'gray',
                        OffboardingStatus::SettlementPending => 'warning',
                        OffboardingStatus::NoticePeriod => 'info',
                        OffboardingStatus::Completed => 'success',
                    }),

                /*
                | ⚠️ الجوابُ من `duesCleared()` لا من العمود. النعم/لا هو كلُّ ما
                | يحتاجُه المُشغِّلُ ليعرفَ لماذا لا يكتملُ الخروج؛ وأيُّ رقمٍ خلفَه
                | ليس من شأنِ هذه الشاشةِ ولا من شأنِ هذه الوحدة.
                */
                TextColumn::make('dues_cleared')
                    ->label('خلوّ الذمّة')
                    ->badge()
                    ->getStateUsing(fn (TeacherOffboarding $record): string => $record->duesCleared()
                        ? 'مُخلَصة'
                        : 'لم تُخلَصْ بعد')
                    ->color(fn (TeacherOffboarding $record): string => $record->duesCleared() ? 'success' : 'warning'),

                TextColumn::make('students_notified_at')
                    ->label('أُخطرَ الطلّاب')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يُخطَروا')
                    ->toggleable(),

                TextColumn::make('notice_ends_at')
                    ->label('نهاية مهلة الإخطار')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable()
                    // المهلةُ التي انقضتْ لم تعدْ مانعاً، فتُقرأُ خضراء.
                    ->color(fn (TeacherOffboarding $record): string => $record->notice_ends_at !== null && $record->notice_ends_at->isPast()
                        ? 'success'
                        : 'gray'),

                // وجودُ الأرشيفِ لا مسارُه: المسارُ مؤشِّرٌ إلى ملفٍّ يحملُ كلَّ ما
                // ألّفَه إنسانٌ واحد، وتنزيلُه يمرُّ بمسارِ التصديرِ الموقَّع.
                TextColumn::make('content_export_path')
                    ->label('أرشيف المحتوى')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(fn (TeacherOffboarding $record): string => $record->content_export_path !== null
                        ? 'جاهز'
                        : 'لم يُنشأ')
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->label('اكتملَ في')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('طُلبَ في')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                // الخياراتُ مشتقّةٌ من `cases()`: قائمةٌ ثانيةٌ مكتوبةٌ باليدِ تفترقُ
                // عن الأولى عند أوّلِ حالةٍ تُضاف.
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(OffboardingStatus::cases())
                        ->mapWithKeys(fn (OffboardingStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),

                Filter::make('notice_elapsed')
                    ->label('انقضتْ مهلة الإخطار')
                    /**
                     * @param  Builder<TeacherOffboarding>  $query
                     */
                    ->query(function (Builder $query): void {
                        $query
                            ->whereNotNull('notice_ends_at')
                            ->where('notice_ends_at', '<', now())
                            ->where('status', '!=', OffboardingStatus::Completed->value);
                    }),
            ]);
    }

    /**
     * ⚠️ قراءةٌ على مستوى المنصّة، والالتفافُ هنا مطلوبٌ فعلاً.
     *
     * `TeacherOffboarding` هو النموذجُ الوحيدُ في هذه الوحدةِ الذي يستعملُ
     * `BelongsToWorkspace`، و`WorkspaceContext::id()` ترتدُّ إلى
     * `users.last_workspace_id` لكلِّ مستخدمٍ بمن فيهم مديرُ المنصّة — فتقريرٌ
     * تُركَ منطاقاً يعرضُ خروجَ مدرّسٍ واحدٍ على أنَّه كلُّ ما لدى المنصّة، وينجحُ
     * في اختبارِه على تجهيزةٍ بمساحةِ عملٍ واحدة.
     *
     * والالتفافُ لكلِّ نموذجٍ على حِدة، فيُعادُ داخلَ كلِّ تحميلٍ مسبقٍ لنموذجٍ
     * منطاق — ولا واحدَ من `teacher` و`workspace` كذلك: `User` و`Workspace` لا
     * يستعملانِ `BelongsToWorkspace`، فليس في العلاقتينِ نطاقٌ يُعادُ الالتفافُ
     * عليه. والتحميلُ غيرُ مقيَّدِ الأعمدةِ عن قصد: `name` قارئٌ فوق `first_name`
     * و`last_name`، وتحميلٌ مقيَّدٌ لا يسمّيهما يعرضُ كلَّ صفٍّ فارغاً بمئتينِ ولا
     * خطأَ في أيِّ مكان.
     *
     * ⚠️ والمكتوبُ هنا هو جسدُ `BelongsToWorkspace::scopeWithoutWorkspaceScope()`
     * حرفاً بحرف، لا التفافٌ ثانٍ بجانبِه: `Resource::getEloquentQuery()` تُعيدُ
     * `Builder<Model>`، فنطاقُ النموذجِ لا يُحَلُّ عليها باسمِه، والطريقُ الوحيدُ
     * إلى تسميتِه كان `@var` داخليّةً تُملي على المحلِّلِ نوعاً — وهي ممنوعة.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->with(['teacher', 'workspace']);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherOffboardings::route('/'),
        ];
    }
}
