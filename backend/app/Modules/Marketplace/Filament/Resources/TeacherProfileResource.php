<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources;

use App\Models\User;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\ReinstateTeacher;
use App\Modules\Marketplace\Actions\SubmitTeacherApplication;
use App\Modules\Marketplace\Actions\SuspendTeacher;
use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\RelationManagers;
use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Policies\TeacherProfilePolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * ما صارَ إليه الطلبُ بعدَ قبوله — الشاشةُ التي لم تكن موجودة.
 *
 * {@see TeacherApplicationResource} يقبلُ الطلبَ فيقلبُ `approval_status` على هذا
 * الصفّ، ولم يكن في اللوحةِ مكانٌ يُقرأُ فيه الأثر: من هو معتمَدٌ الآن، ومن هو
 * موقوف، وعلى مَن شكوى مفتوحة، ولماذا درجةُ ثقتِه فارغة.
 *
 * ⚠️ لا يُكتَبُ من هنا حقلٌ واحدٌ مباشرةً، بلا استثناء: كلُّ زرٍّ ينادي الـAction
 * التي ينادِيها الـAPI. `is_publicly_listed` مشتقٌّ من الاعتمادِ ومن مشاركةِ
 * المساحةِ ولا يُسنَدُ إليه، ودرجةُ الثقةِ يحسبُها عملٌ ليليّ — وكلاهما في
 * `$fillable`، فنموذجٌ يحفظُ مصفوفتَه خاماً يتخطّاهما بصمتٍ ويظهرُ صحيحاً في
 * الجدول. {@see UpdateTeacherProfile} تحملُ القائمةَ البيضاءَ وحدَها.
 *
 * ⚠️ وزرُّ «اعتماد» يتفرّعُ ولا يُكرَّر: إن كان للملفِّ طلبٌ لم يُبَتَّ فيه بعدُ
 * فالقرارُ قرارُ الطلب — {@see ApproveTeacherApplication} وحدَها تختمُ مشاركةَ
 * المساحةِ وترسلُ الإشعارَ وتسجّلُ المراجِعَ وتُغلقُ الطلب؛ واعتمادٌ من هنا بدونِها
 * يتركُ الطلبَ معلّقاً إلى الأبد. وإن لم يكن للملفِّ طلبٌ أصلاً — وهي حالةٌ
 * قِيسَت على الإنتاج، صفوفُ بذورٍ سبقت المعالجَ — فلا طابورَ فيه ما يُضغَط،
 * و{@see ReinstateTeacher} هي البابُ الوحيد. بلا هذا التفرّعِ كان في المنتَجِ
 * كلِّه صفرُ أزرارِ اعتماد.
 *
 * ⚠️ و`marketplace.teachers.suspend` كانت صلاحيّةً لها Action ولها مسارانِ في
 * الـAPI ولا شاشةَ لها في المنتَجِ كلِّه: مدرّسٌ معتمَدٌ وجبَ إيقافُه لا يُوقَفُ
 * من أيِّ مكان. القدرةُ والزرُّ يُشحنانِ معاً.
 *
 * ⚠️ `canView()` مكتوبٌ صراحةً فوقَ السياسة. {@see TeacherProfilePolicy::view()}
 * يسمحُ لصاحبِ الملفّ نفسِه — وهو صحيحٌ لواجهةِ المدرّس — وكلُّ مدرّسٍ يصلُ إلى
 * `/admin`. بدونَ هذا السطرِ يفتحُ المدرّسُ صفحةَ ملفِّه هنا فيقرأُ في مديرِ
 * العلاقاتِ تقييماتِ طلابِه صفّاً صفّاً، وهو بعينِه ما يمنعُه FR-021.
 */
class TeacherProfileResource extends Resource
{
    protected static ?string $model = TeacherProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'السوق والتصنيف';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'search_name';

    /*
    | قائمةٌ واحدةٌ للشارةِ وللمرشِّحِ ولمديرِ العلاقاتِ في {@see TaxonomyResource}.
    | قائمتان تفترقان عندَ أوّلِ حالةٍ يضيفها أحد، بصمت.
    */
    public const APPROVAL_STATUSES = [
        TeacherProfile::STATUS_PENDING => 'قيد المراجعة',
        TeacherProfile::STATUS_APPROVED => 'معتمَد',
        TeacherProfile::STATUS_REJECTED => 'مرفوض',
        TeacherProfile::STATUS_SUSPENDED => 'موقوف',
    ];

    private const TRUST_BANDS = [
        TeacherProfile::BAND_BUILDING => 'قيد البناء',
        TeacherProfile::BAND_HIGH => 'مرتفعة',
        TeacherProfile::BAND_MEDIUM => 'متوسّطة',
        TeacherProfile::BAND_LOW => 'منخفضة',
    ];

    public static function getNavigationLabel(): string
    {
        return 'ملفّات المدرّسين';
    }

    public static function getModelLabel(): string
    {
        return 'ملفّ مدرّس';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ملفّات المدرّسين';
    }

    public static function approvalLabel(string $state): string
    {
        return self::APPROVAL_STATUSES[$state] ?? $state;
    }

    public static function approvalColor(string $state): string
    {
        return match ($state) {
            TeacherProfile::STATUS_APPROVED => 'success',
            TeacherProfile::STATUS_PENDING => 'info',
            TeacherProfile::STATUS_SUSPENDED => 'warning',
            TeacherProfile::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_REVIEW) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * ⚠️ مكتوبٌ فوقَ السياسةِ صراحةً كما `canView()` وللسببِ نفسِه.
     * {@see TeacherProfilePolicy::update()} يسمحُ لصاحبِ الملفِّ — وهو صحيحٌ
     * لواجهةِ المدرّسِ حيثُ يحرّرُ نفسَه — وكلُّ مدرّسٍ يصلُ إلى `/admin`. تعديلُ
     * ما يظهرُ للطلابِ عن إنسانٍ قرارُ فريقِ المراجعةِ وحدَه.
     */
    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_APPROVE) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    /**
     * ما يكتبُه المعالجُ يُعدَّلُ، وما يكتبُه قرارٌ لا يُعدَّل.
     *
     * فهذه الحقولُ هي بعينِها التي يكتبُها {@see SubmitTeacherApplication} من
     * الخطوةِ الثانية. وما ليس هنا ليس سهواً: `approval_status` و
     * `is_publicly_listed` قرارانِ لهما أزرارُهما، و`trust_score` والعدّاداتُ
     * يكتبُها عملٌ ليليّ، و`hourly_rate` مدخلُ المدرّسِ ومسارُ تغييرِه طلبُ
     * تعديلِ سعر، و`is_verified` قرارٌ لا بيان.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ما يظهرُ في السوق')
                ->columns(2)
                ->schema([
                    /*
                    | السلَجُ هيكلٌ ساكنٌ من الحروفِ الصامتة — «أحمد المنصوري» ⇐
                    | `ahmd-almnswry` — ولا شيءَ يستعيدُ الحركاتِ من الرسم. تصحيحُه
                    | باليدِ هو الجوابُ، وهو سببُ كونِ العمودِ قابلاً للإسناد.
                    | ⚠️ والعنوانُ القديمُ يتوقّفُ فوراً بلا تحويل.
                    */
                    TextInput::make('slug')
                        ->label('عنوانه في السوق')
                        ->helperText('تغييرُه يُعطِّلُ العنوانَ القديمَ فوراً؛ الرابطُ بالمعرّفِ يبقى يعمل.')
                        ->maxLength(160)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true),

                    TextInput::make('years_experience')
                        ->label('سنوات الخبرة')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(70),

                    TextInput::make('headline')
                        ->label('السطر التعريفيّ')
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Textarea::make('bio')
                        ->label('النبذة')
                        ->rows(6)
                        ->maxLength(5000)
                        ->columnSpanFull(),

                    TagsInput::make('qualifications')
                        ->label('المؤهّلات')
                        ->columnSpanFull(),

                    TagsInput::make('teaching_languages')
                        ->label('لغات التدريس')
                        ->columnSpanFull(),
                ]),

            Section::make('التخصّص')
                ->columns(2)
                ->schema([
                    /*
                    | ⚠️ `options()` لا `relationship()`: الثاني يجعلُ Filament
                    | يكتبُ الجدولَ الوسيطَ بنفسِه بعدَ الحفظ، فيصيرُ للحفظِ كاتبان
                    | — وإبطالُ ذاكرةِ السوقِ يقعُ قبلَ نصفِه، فتبقى البطاقةُ
                    | تحملُ الموادَّ القديمةَ إلى أن تنتهيَ الذاكرةُ وحدَها.
                    */
                    Select::make('subjects')
                        ->label('المواد')
                        ->multiple()
                        ->preload()
                        ->options(fn (): array => Subject::query()
                            ->orderBy('sort_order')
                            ->pluck('name_ar', 'id')
                            ->all()),

                    Select::make('grade_levels')
                        ->label('المراحل')
                        ->multiple()
                        ->preload()
                        ->options(fn (): array => GradeLevel::query()
                            ->orderBy('sort_order')
                            ->pluck('name_ar', 'id')
                            ->all()),
                ]),
        ]);
    }

    /**
     * الاعتمادُ: قرارٌ واحدٌ ببابَين، والفرعُ يُقرأُ من وجودِ طلبٍ لم يُبَتَّ فيه.
     *
     * ⚠️ التخطّي داخلَ الاستعلامِ ضروريّ: `WorkspaceContext::id()` يرتدُّ إلى
     * `last_workspace_id` حتّى للمشرِفِ العامّ، فطلبُ مدرّسٍ في مساحةٍ أخرى لا
     * يُرى — والزرُّ عندَها يسلكُ الفرعَ الخطأَ ويتركُ الطلبَ معلّقاً.
     *
     * ⚠️ و`setRelation` ليس تحسيناً: {@see ApproveTeacherApplication} تقرأُ
     * `$application->teacherProfile` وترمي إن كان `null`، وذلك الاستعلامُ يطبّقُ
     * نطاقَ {@see TeacherProfile} — فيعودُ فارغاً لملفٍّ خارجَ مساحةِ المراجِع.
     */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('اعتماد')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('يظهرُ المدرّسُ في السوقِ فورَ الاعتمادِ إن كانت مساحتُه مشارِكة.')
            ->visible(fn (TeacherProfile $record): bool => $record->approval_status !== TeacherProfile::STATUS_APPROVED
                && (Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_APPROVE) ?? false))
            ->action(function (TeacherProfile $record): void {
                $application = TeacherApplication::query()
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->where('teacher_profile_id', $record->getKey())
                    ->where('status', '!=', TeacherApplication::STATUS_APPROVED)
                    ->latest('id')
                    ->first();

                /** @var User $reviewer */
                $reviewer = Auth::user();

                if ($application !== null) {
                    $application->setRelation('teacherProfile', $record);
                    app(ApproveTeacherApplication::class)->handle($application, $reviewer);

                    return;
                }

                app(ReinstateTeacher::class)->handle($record);
            });
    }

    public static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('إيقاف')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('يختفي المدرّسُ من السوقِ فوراً. الطلابُ المسجَّلونَ لا يفقدونَ ما دفعوا ثمنَه.')
            ->visible(fn (TeacherProfile $record): bool => $record->approval_status === TeacherProfile::STATUS_APPROVED
                && (Auth::user()?->can(Permissions::MARKETPLACE_TEACHERS_SUSPEND) ?? false))
            ->action(fn (TeacherProfile $record) => app(SuspendTeacher::class)->handle($record));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                /*
                | `search_name` لا `user.name`: العمودُ مخزَّنٌ على هذا الجدولِ
                | ويُبحَثُ فيه مباشرةً بلا ضمٍّ لجدولِ المستخدمين — و`users` لا
                | تحملُ عمودَ `name` أصلاً، إنّما هو مُلحِقٌ فوقَ الاسمَين.
                */
                TextColumn::make('search_name')
                    ->label('المدرّس')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('user.email')
                    ->label('البريد')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('workspace.name')
                    ->label('مساحة العمل')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('approval_status')
                    ->label('الاعتماد')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::approvalLabel($state))
                    ->color(fn (string $state): string => self::approvalColor($state))
                    ->sortable(),

                IconColumn::make('is_publicly_listed')
                    ->label('معروض في السوق')
                    ->boolean(),

                IconColumn::make('is_verified')
                    ->label('موثَّق')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                | ⚠️ دونَ حدِّ البياناتِ الدرجةُ `null` وشريحتُها «قيد البناء»، وليست
                | صفراً أبداً: مدرّسٌ جديدٌ ليس مدرّساً غيرَ موثوق (FR-024). لذا
                | يقبلُ المُنسِّقُ `?int` — تمريرُ `int` هنا خطأُ نوعٍ على أوّلِ صفّ.
                */
                TextColumn::make('trust_score')
                    ->label('درجة الثقة')
                    ->badge()
                    ->formatStateUsing(fn (?int $state, TeacherProfile $record): string => $state === null
                        ? 'قيد البناء'
                        : $state.' · '.(self::TRUST_BANDS[$record->trustScoreBand()] ?? ''))
                    ->color(fn (?int $state): string => $state === null ? 'gray' : 'info')
                    ->sortable(),

                TextColumn::make('average_rating')
                    ->label('متوسّط التقييم')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reviews_count')
                    ->label('التقييمات')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                | العدُّ من {@see self::getEloquentQuery()}، لا من إغلاقٍ داخلَ
                | العمود: مُنسِّقُ العمودِ يُنفَّذُ مرّةً لكلِّ صفّ، فاستعلامٌ داخلَه
                | هو N+1 بحكمِ البناء.
                */
                TextColumn::make('open_complaints_count')
                    ->label('شكاوى مفتوحة')
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('completed_sessions_count')
                    ->label('حصص مكتملة')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('أُنشئ')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('approval_status')
                    ->label('الاعتماد')
                    ->options(self::APPROVAL_STATUSES),

                TernaryFilter::make('is_publicly_listed')->label('معروض في السوق'),

                TernaryFilter::make('is_verified')->label('موثَّق'),
            ])
            ->recordActions([
                self::approveAction(),
                self::suspendAction(),
                EditAction::make()->label('تعديل'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المدرّس')
                ->columns(2)
                ->schema([
                    TextEntry::make('search_name')->label('الاسم')->placeholder('—'),
                    TextEntry::make('user.email')->label('البريد')->copyable(),
                    TextEntry::make('workspace.name')->label('مساحة العمل'),
                    TextEntry::make('slug')->label('عنوانه في السوق')->placeholder('—'),
                    TextEntry::make('headline')->label('السطر التعريفيّ')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('حالة الاعتماد')
                ->description('كلُّ ما في هذا القسمِ يُكتَبُ من إجراءاتِ المراجعة؛ الشاشةُ تقرأُ ولا تكتب.')
                ->columns(2)
                ->schema([
                    TextEntry::make('approval_status')
                        ->label('الاعتماد')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::approvalLabel($state))
                        ->color(fn (string $state): string => self::approvalColor($state)),
                    IconEntry::make('is_verified')->label('موثَّق')->boolean(),
                    IconEntry::make('is_publicly_listed')->label('معروض في السوق')->boolean(),
                    TextEntry::make('created_at')->label('أُنشئ')->dateTime('Y-m-d H:i'),
                ]),

            Section::make('أرقام المنصّة')
                ->columns(3)
                ->schema([
                    TextEntry::make('trust_score')
                        ->label('درجة الثقة')
                        ->formatStateUsing(fn (?int $state, TeacherProfile $record): string => $state === null
                            ? 'قيد البناء'
                            : $state.' · '.(self::TRUST_BANDS[$record->trustScoreBand()] ?? '')),
                    TextEntry::make('average_rating')->label('متوسّط التقييم')->placeholder('—'),
                    TextEntry::make('reviews_count')->label('عدد التقييمات'),
                    TextEntry::make('completed_sessions_count')->label('حصص مكتملة'),
                    TextEntry::make('cancelled_sessions_count')->label('حصص ملغاة'),
                    TextEntry::make('students_taught_count')->label('طلاب درّسهم'),

                    /*
                    | ⚠️ حضورُ المدرّسِ نفسِه لا حضورُ طلابِه: نصيبُ الحصصِ المحسوبةِ
                    | التي سُلِّمَت فعلاً. غيابُ طالبٍ لا يمسُّ هذا الرقمَ إطلاقاً،
                    | والاسمُ يُقرأُ على العكس — لذا التسميةُ تقولُها.
                    */
                    TextEntry::make('attendance_rate')
                        ->label('التزام المدرّس بالحصص ٪')
                        ->placeholder('—'),

                    TextEntry::make('response_rate')->label('سرعة الردّ ٪')->placeholder('—'),
                    TextEntry::make('years_experience')->label('سنوات الخبرة'),
                ]),

            Section::make('التخصّص')
                ->columns(2)
                ->schema([
                    TextEntry::make('subjects.name_ar')->label('المواد')->badge()->placeholder('—'),
                    TextEntry::make('gradeLevels.name_ar')->label('المراحل')->badge()->placeholder('—'),
                ]),
        ]);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ReviewsRelationManager::class,
            RelationManagers\ComplaintsRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeacherProfiles::route('/'),
            'view' => Pages\ViewTeacherProfile::route('/{record}'),
            'edit' => Pages\EditTeacherProfile::route('/{record}/edit'),
        ];
    }

    /**
     * ⚠️ قراءةٌ بصلاحيةِ منصّةٍ تُصرِّحُ بتخطّي النطاقِ صراحةً، والتخطّي لكلِّ
     * نموذجٍ على حدة. `WorkspaceContext::id()` يرتدُّ إلى `last_workspace_id` حتّى
     * للمشرِفِ العامّ، فقائمةٌ منطاقةٌ تعرضُ مدرّسي مساحةٍ واحدةٍ على أنّهم
     * المنصّةُ كلُّها — وتمرُّ خضراءَ في أيِّ اختبارٍ بمساحةٍ واحدة.
     *
     * وعدُّ الشكاوى يحملُ التخطّيَ داخلَه: `withCount` يبني استعلاماً فرعيّاً
     * بنطاقاتِ `Complaint` نفسِه، لا بنطاقاتِ الاستعلامِ الحاوي.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            // بلا تقييدِ أعمدة: `users` لا تحملُ `name` — هو مُلحِقٌ فوقَ
            // `first_name`/`last_name`، فقائمةٌ مقيَّدةٌ تُفرِغُ كلَّ اسمٍ بصمت.
            ->with(['user', 'workspace'])
            ->withCount([
                'complaints as open_complaints_count' => fn (Builder $complaints): Builder => $complaints
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->where('status', Complaint::STATUS_OPEN),
            ]);
    }
}
