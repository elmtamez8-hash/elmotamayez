<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\NavigationGroups;
use App\Filament\Resources\CourseResource\Pages;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\RecordLink;
use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Enums\CourseVisibility;
use App\Modules\Courses\Filament\Pages\ReviewPromoVideos;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\Currency;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroups::CONTENT;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'الكورسات';
    }

    public static function getModelLabel(): string
    {
        return 'كورس';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الكورسات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التعريف')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        /*
                        | ⚠️ UNIQUE ACROSS THE PLATFORM, DELETED COURSES INCLUDED —
                        | the same rule the API's requests carry. Without it a slug
                        | another course holds reached the unique index and answered
                        | a raw integrity error. Filament's `unique()` is a raw
                        | `Rule::unique`, so a soft-deleted course still holds its
                        | address: a deleted course's public URL must never start
                        | serving somebody else's course. Required because the
                        | column is NOT NULL — an emptied field was the same 500.
                        */
                        TextInput::make('slug')
                            ->label('المُعرِّف')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('subject_id')
                            ->label('المادّة')
                            ->relationship('subject', 'name')
                            ->searchable()
                            ->preload(),
                        /*
                        | ⚠️ المجموعةُ ومقابلُها العربيُّ يعيشان في النوعِ لا هنا. كانت
                        | الحالةُ مكتوبةً ثلاثَ مرّاتٍ في هذا الملفِّ وحدَه — في النموذجِ
                        | وفي المرشِّحِ وفي الشارة — فطبعَتِ الشارةُ `published` خاماً
                        | بينما النموذجُ يعرضُها بالإنجليزيّة.
                        |
                        | ⚠️ وقائمةُ الظهورِ كانت **خيارَين** بينما `CourseVisibility`
                        | تحملُ ثلاثة: `hidden` موجودةٌ في قاعدةِ البيانات، ولا سبيلَ إلى
                        | بلوغِها ولا إلى الخروجِ منها من الشاشة. اشتقاقُ الخياراتِ من
                        | `cases()` يجعلُ ذلك مستحيلاً بالبناء.
                        */
                        Select::make('status')
                            ->label('الحالة')
                            ->options(CourseStatus::options())
                            ->required(),
                        /*
                        | ⚠️ الظهورُ قرارُ **المدرّس** (قرارُ المالك ٢٠٢٦-٠٩-٢٦)، والسؤالُ
                        | هو `CoursePolicy::changeVisibility()` كما في الـAPI — لا
                        | `courses.update`. الحقلُ مُعطَّلٌ لمن لا يُجيبُه بنعم، وحقلٌ
                        | مُعطَّلٌ لا يُرسَلُ فتبقى القيمةُ المحفوظة. (مديرُ المنصّةِ
                        | يمرُّ بـ`Gate::before`، وهو اليومَ قارئُ الشاشةِ الوحيد — فالشرطُ
                        | لمن يُفتَحُ له البابُ غداً، لا له.)
                        */
                        Select::make('visibility')
                            ->label('الظهور')
                            ->options(CourseVisibility::options())
                            ->disabled(fn (?Course $record): bool => ! ($record instanceof Course
                                && (Auth::user()?->can('changeVisibility', $record) ?? false)))
                            ->required(),
                        /*
                        | ⚠️ **هنا لأنّ اللوحةَ تُصحِّح، لا لأنّها تُنشئ.** هذا المَورِدُ
                        | يحملُ `EditCourse` و`ListCourses` ولا بابَ إنشاءٍ فيه — لا
                        | صفحةَ ولا زرَّ نافذة (أُزيلَ زرُّ «إنشاء» من `ListCourses`
                        | ٢٠٢٦-٠٩-٢٧: كانَ `new Course($data)` يختمُ مساحةَ **الموظّف**
                        | ولا يمرُّ بـ{@see CreateCourse} ولا بقاعدةٍ من قواعدِه). فالحقلُ
                        | هو ما يجعلُ نوعاً خاطئاً قابلاً للتصحيحِ من المنصّة — والهجرةُ
                        | تشتقُّ «جماعي» من المجموعاتِ وحدَها وتتركُ الباقي عمداً، فبقيَ
                        | صفٌّ يحتاجُ من يقولُ له ما هو.
                        |
                        | الكورسُ يُنشِئُه مدرّسُه من الموقع. الخياراتُ من
                        | `Course::types()`، الإملاءُ الواحد.
                        */
                        Select::make('course_type')
                            ->label('نوع الكورس')
                            ->options(Course::typeLabels())
                            ->required(),
                        /*
                        | ⚠️ أعضاءُ مساحةِ **المقرَّرِ**، لا مساحةِ من يقرأُ الشاشة.
                        | كانت القائمةُ تُبنى من `WorkspaceContext::current()`، وهو
                        | `null` لمديرِ المنصّةِ (يرجعُ إلى `users.last_workspace_id`
                        | ولا شيءَ يكتبُه له) — فتخرجُ فارغةً، ويعرضُ الحقلُ القيمةَ
                        | الخامَّ: رقمُ المستخدِمِ `37` مكانَ بريدِه، على شاشةِ تعديلٍ
                        | حيّة. والقراءةُ من `$record` تُصلِحُ الأمرَينِ معاً: تملأُ
                        | القائمةَ لأيِّ قارئ، وتمنعُ إسنادَ المقرَّرِ إلى شخصٍ من
                        | مساحةٍ أخرى.
                        |
                        | ⚠️ **ومن يُدرِّسُ فيها وحدَهم، بدورِ العضويّة.** `workspace_members`
                        | يحملُ صفوفَ الطلّابِ أيضاً، فكانت القائمةُ تعرضُ كلَّ طالبٍ
                        | مسجَّلٍ مؤلِّفاً محتمَلاً للكورس. «المؤلِّفُ» دورُ العضويّةِ
                        | (مالكٌ أو مدرّس) لا مجرّدُ العضويّة (`docs/gotchas/courses.md`).
                        | و`in()` يُكرِّرُ الشرطَ على الطلب.
                        */
                        Select::make('created_by')
                            ->label('أنشأه')
                            ->options(fn (?Course $record): array => self::authorsOf($record))
                            ->in(fn (?Course $record): array => array_keys(self::authorsOf($record)))
                            ->searchable(),
                    ]),

                Section::make('التسعير')
                    ->columns(2)
                    ->schema([
                        // ⛔ قرارُ المالك (٢٠٢٦-٠٩-٢٧): يُكتَبُ 49.99 لا 4999، ويُخزَّنُ
                        // بالوحدةِ الصغرى كما كان — والنموذجُ هو الذي يحوِّل: الحقلُ
                        // مربوطٌ بـ`Course::price` الافتراضيّة فوقَ `price_minor`.
                        // كانَ `integer()` بلا حدٍّ أدنى ولا `required`: السالبُ
                        // يُحفَظ، والفراغُ يرتطمُ بـ`NOT NULL` صفحةَ خطأ.
                        MoneyInput::make('price', fn (Get $get): mixed => $get('currency'))
                            ->label('السعر')
                            ->required()
                            ->helperText('بالعملةِ المختارة — ٤٩٫٩٩ تُكتَبُ 49.99')
                            ->default(0),
                        /*
                        | ⚠️ الافتراضُ كانَ `'USD'` — بقيّةٌ من هيكلِ لارافيل لا من
                        | المنتَج — وكانَ الحقلُ نصّاً حرّاً. فكلُّ كورسٍ يُنشَأُ دونَ
                        | لمسِ الحقلِ كانَ يُسعَّرُ بالدولارِ ويُعرَضُ به، بينما عملةُ
                        | المنصّةِ هي `BillingSettings::currency()` — الريالُ القطريّ.
                        */
                        Select::make('currency')
                            ->label('العملة')
                            ->options(Currency::options())
                            ->live()
                            ->required()
                            ->default(fn (): string => app(BillingSettings::class)->currency()),
                        /*
                        | ⛔ «مجاني» قرارٌ صريحٌ لا استنتاجٌ من السعر (قرارُ المالك
                        | ٢٠٢٦-٠٩-٢٥): الكورسُ يُباعُ بالباقاتِ وحدَها، وكلُّ كورسٍ
                        | جديدٍ يولَدُ بسعرِ صفر — فالاستنتاجُ كانَ يفتحُه مجّاناً.
                        */
                        Checkbox::make('is_free_enrollment')
                            ->label('كورس مجاني')
                            ->helperText('يسجّل فيه أي طالب بلا دفع. بدونه لا يُدخَل الكورس إلا بباقة.')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->sortable()->wrap(),
                /*
                | ⚠️ بلا `searchable()` ولا `sortable()` على عمودِ `name`، وبلا تقييدِ
                | أعمدةٍ في التحميلِ المسبق: `users` لا عمودَ فيه بهذا الاسم — إنّه
                | سِمةٌ محسوبةٌ فوقَ `first_name` و`last_name`. تحميلٌ مقيَّدٌ يطبعُ
                | فراغاً في كلِّ صفٍّ بردٍّ ٢٠٠ ولا خطأ، وبحثٌ عليه يبني SQL على عمودٍ
                | لا وجودَ له.
                */
                TextColumn::make('workspace.name')
                    ->label('المدرّس / الأكاديميّة')
                    ->placeholder('—')
                    ->url(fn (Course $record): ?string => RecordLink::to(WorkspaceResource::class, $record->workspace))
                    ->toggleable(),
                TextColumn::make('subject.name')
                    ->label('المادّة')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => CourseStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_free_enrollment')
                    ->label('مجاني')
                    ->boolean()
                    ->sortable(),
                // The model's major-unit attribute; the sort stays on the stored column.
                TextColumn::make('price')
                    ->label('السعر')
                    ->money(fn (Course $record): string => $record->currency)
                    ->sortable(['price_minor']),
                /*
                | العدُّ من `withCount` لا من إغلاقٍ داخلَ العمود: المورِدُ يُنفَّذُ مرّةً
                | لكلِّ صفّ، فاستعلامٌ داخلَه هو N+1 بالبناء لا بالصدفة.
                */
                TextColumn::make('enrollments_count')
                    ->label('المسجَّلون')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('أنشأه')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                /*
                | حالةُ الفيديو الترويجيّ (٠١٨). لونُ `pending` تحذيرٌ لا رمادٌ:
                | صفٌّ ينتظرُ قراراً بشريّاً، ورماديُّه يجعلُه يختفي في القائمة.
                */
                TextColumn::make('promo_video_status')->label('الفيديو الترويجي')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Course::PROMO_PENDING => 'بانتظار المراجعة',
                        Course::PROMO_APPROVED => 'معتمَد',
                        Course::PROMO_REJECTED => 'مرفوض',
                        default => 'لا يوجد',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Course::PROMO_PENDING => 'warning',
                        Course::PROMO_APPROVED => 'success',
                        Course::PROMO_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('أُنشئ في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(CourseStatus::options()),
                SelectFilter::make('visibility')
                    ->label('الظهور')
                    ->options(CourseVisibility::options()),
                SelectFilter::make('promo_video_status')
                    ->label('الفيديو الترويجي')
                    ->options([
                        Course::PROMO_PENDING => 'بانتظار المراجعة',
                        Course::PROMO_APPROVED => 'معتمَد',
                        Course::PROMO_REJECTED => 'مرفوض',
                        Course::PROMO_NONE => 'لا يوجد',
                    ]),
            ])
            /*
            | ⚠️ لا إجراءَ «مراجعة الفيديو» هنا بعدَ اليوم. كانَ البابَ الوحيدَ
            | لـ`marketplace.promo.review`، وهذه القائمةُ لمديرِ المنصّةِ وحدَه —
            | فمسؤولُ الامتثالِ الذي يحملُ الصلاحيّةَ لم يكنْ يصلُ فيديو واحداً.
            | للمراجعةِ شاشتُها: {@see ReviewPromoVideos}، وبابٌ واحدٌ لفعلٍ واحد.
            */
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    /*
                    | The refusal lives in `Course::booted()`; this names it
                    | before anything is deleted, so a selection holding one
                    | bought course deletes nothing rather than a silent part of
                    | it. Never add `fetchSelectedRecords(false)` here — that is
                    | a query delete, which never reaches the model hook.
                    */
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $refused = $records->first(
                                fn (mixed $record): bool => $record instanceof Course && $record->deletionRefusal() !== null,
                            );

                            if ($refused instanceof Course) {
                                Notification::make()
                                    ->danger()
                                    ->title('لم يُحذف شيء: «'.$refused->title.'» — '.$refused->deletionRefusal())
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * ⚠️ THE SUPER ADMIN ONLY — the same reasoning as {@see ExamResource::canViewAny()}.
     * Every course permission is a TENANT permission, held by a platform officer
     * who owns a workspace in that workspace, and this list is platform-wide.
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny();
    }

    /**
     * ⚠️ PLATFORM-WIDE, AND THE BYPASS IS REPEATED IN THE COUNT.
     *
     * With the scope left on, a super admin who has a `last_workspace_id` saw
     * that one workspace's courses — and could not open another's edit page,
     * which resolves its record through this query. `withCount('enrollments')`
     * is a subquery the `Enrollment` scope applies to all over again, so another
     * workspace's course read «0 مسجَّلين» with a full class. `workspace`,
     * `subject` and `creator` are platform-owned and carry no scope.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->with(['workspace', 'subject', 'creator'])
            ->withCount([
                'enrollments' => fn (Builder $query): Builder => $query->withoutGlobalScope(WorkspaceScope::class),
            ]);
    }

    /**
     * The people who may be named as a course's author: every STAFF member of
     * the COURSE's workspace (owner, teacher, assistant, custom role), by pivot
     * role — never a student, because `workspace_members` carries student rows
     * too. An assistant holds `courses.create`, so an allow-list of owner and
     * teacher refused to save every course an assistant wrote.
     *
     * @return array<int, string>
     */
    private static function authorsOf(?Course $course): array
    {
        $workspace = $course instanceof Course ? $course->workspace : null;

        if ($workspace === null) {
            return [];
        }

        /** @var array<int, string> $authors */
        $authors = $workspace->members()
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->pluck('users.email', 'users.id')
            ->all();

        return $authors;
    }

    public static function getRelations(): array
    {
        return [
            CourseResource\RelationManagers\EnrollmentsRelationManager::class,
            CourseResource\RelationManagers\ExamsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
