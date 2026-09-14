<?php

declare(strict_types=1);

namespace App\Modules\Learning\Filament\Pages;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\CohortDirectory;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use UnitEnum;

/**
 * إسنادُ طالبٍ مسجَّلٍ إلى مجموعةٍ من `/admin` (٠٣٤ · US1).
 *
 * ⛔ **الكورسُ شرطٌ لا مُرشِّح، وهذه أوّلُ قرارٍ في الملفِّ.** لائحةٌ افتراضُها
 * «الكلّ» تمسحُ **كلَّ تسجيلٍ على المنصّة** ومعها استعلامٌ فرعيٌّ لكلِّ صفّ — وهي
 * كذلك تُسقِطُ العمودَ الأوّلَ من كلِّ فهرسٍ مركَّبٍ يبدأُ بالمساحة، لأنّ القراءةَ
 * هنا تتجاوزُ النطاقَ بالضرورة. والكورسُ هو ما يُربَط.
 *
 * ⚠️ **والحالُ الافتراضيّةُ «بلا مجموعة»، والعمودُ يُظهِرُ المجموعةَ مع ذلك.**
 * المُرشِّحُ يفتحُ لمن أرادَ **إعادةَ** إسنادِ طالبٍ مسنَدٍ سلفاً — وهو نقلٌ
 * مشروعٌ تكتبُه الإدارة — وبلا العمودِ يكونُ الموظَّفُ أمامَ قائمةٍ لا تقولُ له
 * أينَ الطالبُ الآن.
 *
 * ⚠️ **`canAccess()` تسألُ الصلاحيّةَ صراحةً، وكلُّ كتابةٍ تسألُها ثانية.** صفحةُ
 * Filament **لا تستدعي سياسةً إطلاقاً**، وقَبولُ اللوحةِ نفسِها هو «مديرُ منصّةٍ
 * **أو أيُّ صفٍّ في `platform_staff`**» — ومسؤولُ الامتثالِ منهم ولا يحملُ من هذا
 * شيئاً. وقائمةٌ مُرشَّحةٌ تُشكِّلُ طلباً واحداً لا الذي بعدَه: المسارُ يُكتَبُ كما
 * يُنقَر.
 *
 * ⚠️ **والتوثيقُ الثنائيُّ يُسأَلُ هنا لأنّ `/admin` لا يمرُّ من `2fa.required`**
 * (FR-009) — سابقتُه `GrantCreditSubscription` واعتمادُ الطلب، وهذه كتابةٌ من
 * صنفِهما: تُحرِّكُ طالباً بينَ غرفٍ في مساحةِ مدرّسٍ آخر.
 *
 * ⚠️ **وكلُّ قراءةٍ تُعلِنُ تجاوزَ النطاق، ويُكرَّرُ التجاوزُ داخلَ كلِّ ضمٍّ
 * مُسبَق.** التجاوزُ **لكلِّ نموذجٍ** لا للاستعلامِ كلِّه: إسقاطُه عن الجذرِ وحدَه
 * يترُكُ `->with('student')` يعملُ باستعلامِه الخاصّ — وهي طبقةُ ٠٢٤ الخامسة،
 * الوحيدةُ التي لا ترفعُ رمزَ حالةٍ إطلاقاً.
 *
 * @property-read Schema $form
 */
class AssignStudentToCohort extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.assign-student-to-cohort';

    protected static ?string $slug = 'assign-student-to-cohort';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 31;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * خياراتُ المُنتقي، محمَّلةً **مرّةً واحدةً** لا مرّةً لكلِّ صفّ.
     *
     * ⚠️ جسمُ الفعلِ في Filament يُبنى لكلِّ صفٍّ من صفوفِ الصفحة، فاستعلامُ
     * مجموعاتٍ داخلَه هو `N+1` بالبناء — وهي القاعدةُ التي كتبَها
     * `ClassSessionResource` في هذه الشجرةِ أوّلَ مرّة.
     *
     * @var array<string, string>|null
     */
    private ?array $cohortOptions = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::COHORTS_ASSIGN) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'إسناد طالب إلى مجموعة';
    }

    public function getTitle(): string
    {
        return 'إسناد طالب إلى مجموعة';
    }

    /**
     * ⚠️ `$data` يبدأُ `[]`، وحقلُ Filament المبحوثُ يربطُ نفسَه بـ
     * `$wire.entangle('data.course')` — و**entangle يشترطُ وجودَ الخاصّيّةِ
     * سلفاً**. بلا هذه الجملةِ يرسمُ المتصفّحُ الكورسَ مختاراً ولا تصلُ قيمتُه
     * الخادمَ أبداً، وهو عطبٌ قِيسَ على الإنتاجِ في `GrantCreditSubscription`
     * ومرَّ عليه اختبارٌ أخضرُ لأنّ `fillForm()` يتخطّى الربطَ كلَّه.
     */
    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('الكورس')
                        ->description('اختَرِ الكورسَ أوّلاً. القائمةُ تحتَه تسري عليه وحدَه — '
                            .'لأنّ «كلّ الكورسات» مسحٌ لكلِّ تسجيلٍ على المنصّة، لا شاشةٌ يُعمَلُ بها.')
                        ->schema([
                            /*
                            | ⚠️ `withoutWorkspaceScope()` — والسببُ هو الميزةُ
                            | نفسُها: الموظَّفُ ليسَ عضواً في مساحةِ أيِّ مدرّس،
                            | وسياقُه يرجعُ إلى `last_workspace_id` إن كانَ يملكُ
                            | مساحةً هو الآخر. مقيَّداً بالنطاقِ يعرِضُ هذا
                            | المُنتقي حفنةَ كورساتٍ أو لا شيء، بلا جملةٍ تقولُ
                            | لماذا.
                            */
                            Select::make('course')
                                ->label('الكورس')
                                ->required()
                                ->searchable()
                                ->live()
                                ->getSearchResultsUsing(fn (string $search): array => Course::query()
                                    ->withoutWorkspaceScope()
                                    ->where('title', 'like', "%{$search}%")
                                    ->limit(20)
                                    ->pluck('title', 'id')
                                    ->all())
                                ->getOptionLabelUsing(fn ($value): ?string => Course::query()
                                    ->withoutWorkspaceScope()->whereKey($value)->first()?->title)
                                ->helperText('ابحثْ بعنوانِ الكورس.'),
                        ]),
                ]),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->enrollments())
            ->defaultSort('enrolled_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading($this->courseId() === null ? 'اختَرِ الكورسَ أوّلاً' : 'لا أحدَ ينتظرُ إسناداً')
            ->emptyStateDescription($this->courseId() === null
                ? 'الشاشةُ تعملُ على كورسٍ واحدٍ في كلِّ مرّة.'
                : 'كلُّ مسجَّلٍ في هذا الكورسِ في مجموعة. أطفِئِ المُرشِّحَ لترى الجميعَ ولتنقلَ أحدَهم.')
            ->columns([
                // ⚠️ بلا تقييدِ أعمدة، ولا `searchable()`. `name` سِمةٌ مشتقّةٌ من
                // `first_name`/`last_name`، فضمٌّ مُسبَقٌ يسمّي الأعمدةَ يرسمُ
                // **اسماً فارغاً** لكلِّ صفٍّ بلا خطأ — شُحِنَ في ستّةِ مواضعَ في
                // هذه الشجرةِ ووُجِدَ بالمشي على المنتَج لا باختبار.
                TextColumn::make('student.name')->label('الطالب')->placeholder('—')
                    ->description(fn (Enrollment $record): string => (string) $record->student->email),
                TextColumn::make('current_cohort_name')
                    ->label('مجموعتُه الآن')
                    ->placeholder('بلا مجموعة')
                    ->badge()
                    ->color(fn (mixed $state): string => $state === null ? 'warning' : 'success'),
                TextColumn::make('progress_pct')
                    ->label('التقدّم')
                    ->formatStateUsing(fn (mixed $state): string => ((int) $state).'٪'),
                TextColumn::make('enrolled_at')->label('تاريخُ التسجيل')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                /*
                | ⚠️ افتراضُه «بلا مجموعة»، وهو الاستعلامُ الذي تصفُه المهمّة —
                | لكنّه **مُرشِّحٌ لا حائط**: إعادةُ إسنادِ طالبٍ مسنَدٍ سلفاً نقلٌ
                | مشروعٌ تملكُه الإدارةُ كما تملكُ الأوّل، وشاشةٌ لا تعرِضُه تُرسِلُ
                | الموظَّفَ إلى قاعدةِ البيانات.
                */
                TernaryFilter::make('unassigned')
                    ->label('بلا مجموعة')
                    ->default(true)
                    ->queries(
                        /*
                        | ⚠️ `whereNotExists` مكتوبةً بيدٍ، لا `whereDoesntHave`.
                        | المفتاحُ بينَ التسجيلِ والعضويّةِ **مركَّبٌ** — الطالبُ
                        | والكورسُ معاً — ولا علاقةَ Eloquent تُعبِّرُ عنه، فعلاقةٌ
                        | على أحدِ شطرَيه تردُّ عضويّةَ الطالبِ في كورسٍ آخرَ
                        | وتُخفي من يحتاجُ الإسنادَ فعلاً.
                        */
                        true: fn (Builder $query): Builder => $query->whereNotExists(
                            fn ($sub) => $sub->select(DB::raw(1))
                                ->from('cohort_memberships')
                                ->whereColumn('cohort_memberships.student_user_id', 'enrollments.student_user_id')
                                ->whereColumn('cohort_memberships.course_id', 'enrollments.course_id')
                                ->whereNull('cohort_memberships.closed_at'),
                        ),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                $this->assignAction(),
            ]);
    }

    /**
     * The one write this screen performs.
     *
     * ⚠️ **الصلاحيّةُ تُسأَلُ ثانيةً هنا** ولا يُكتفى بـ`canAccess()`: بينَ فتحِ
     * الصفحةِ وضغطِ الزرِّ جلسةٌ كاملة، وسحبُ صلاحيّةٍ من موظَّفٍ يجبُ أن يُوقِفَ
     * الكتابةَ التالية لا التي بعدَ تسجيلِ الخروج. وإخفاءُ زرٍّ ليسَ حراسة.
     */
    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label('أسنِدْ')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->modalHeading('إسنادُ الطالبِ إلى مجموعة')
            ->modalDescription('يُكتَبُ الإسنادُ باسمِك ووقتِه في سجلِّ عضويّةِ الطالب، ويصلُه إشعارٌ بمواعيدِ مجموعتِه.')
            ->schema([
                Select::make('cohort')
                    ->label('المجموعة')
                    ->required()
                    ->options(fn (): array => $this->assignableCohorts())
                    ->helperText('المفتوحةُ والمغلَقةُ غيرُ المكتمِلة. المغلَقةُ وجهةٌ مشروعةٌ للإدارةِ — '
                        .'«مغلقة» قولٌ عن البابِ لا عن الغرفة.'),
                Textarea::make('reason')
                    ->label('السبب (اختياريّ)')
                    ->maxLength(500)
                    ->helperText('يُحفَظُ في سجلِّ العضويّة، ويقرؤُه المدرّسُ في تاريخِ المجموعة.'),
            ])
            ->action(function (Enrollment $record, array $data): void {
                $this->assign($record, $data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assign(Enrollment $record, array $data): void
    {
        $officer = $this->actor();

        if (! $officer->can(Permissions::COHORTS_ASSIGN)) {
            Notification::make()->danger()->title('لم تعُدْ تملكُ إسنادَ الطلاب.')->persistent()->send();

            return;
        }

        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        // ⚠️ بالمعرِّفِ العلنيِّ **ومعه شرطُ الكورس**: خيارُ النموذجِ يُكتَبُ كما
        // يُنقَر، ومعرِّفُ مجموعةٍ من كورسٍ آخرَ يضعُ الطالبَ في غرفةٍ لا تسجيلَ
        // له فيها.
        $cohort = Cohort::query()->withoutWorkspaceScope()
            ->where('uuid', $data['cohort'] ?? null)
            ->where('course_id', $record->course_id)
            ->first();

        $student = $record->student;

        if (! $cohort instanceof Cohort) {
            Notification::make()->danger()->title('اختيارٌ ناقص، لم يُكتَبْ شيء.')->send();

            return;
        }

        try {
            /*
            | ⚠️ `MoveMember`, NOT A WRITE OF THIS SCREEN'S OWN. It proves the
            | student is enrolled in the course (NFR-001أ), opens the membership
            | through the one writer that claims a seat atomically, and drops any
            | pending transfer request with a sentence the student reads. A second
            | path here would lose one of those three in silence.
            |
            | ⚠️ AND THE DROP NOTE IS THE OFFICER'S, NOT THE DEFAULT. The default
            | says «نقلك المدرّس» — a false sentence on this path, read by the
            | student (٠٣٤ · FR-008).
            */
            app(MoveMember::class)->handle(
                $cohort,
                $student,
                $officer,
                is_string($data['reason'] ?? null) && $data['reason'] !== '' ? $data['reason'] : null,
                dropNote: 'أسندتك إدارة المنصّة إلى مجموعة مباشرةً، فأُغلق طلب انتقالك السابق.',
            );
        } catch (DomainException $e) {
            // The Action's own Arabic sentence — a full group, an archived one,
            // a student already in this very group. Each names what the officer
            // should do instead; a generic message hides which.
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        // The options were loaded once at the first press; the seat count they
        // were built from has just moved.
        $this->cohortOptions = null;

        Notification::make()->success()
            ->title('أُسنِد '.$student->name.' إلى «'.$cohort->name.'»')
            ->send();
    }

    /**
     * تسجيلاتُ الكورسِ المختار — **ولا شيءَ قبلَ اختيارِه**.
     *
     * ⚠️ الحاجزُ في الاستعلامِ لا في ظهورِ الجدول. Filament يبني الجدولَ عندَ
     * التركيب، فشرطٌ مكتوبٌ في `visible()` يُخفي الصفحةَ وقد نُفِّذَ المسحُ
     * بالفعل.
     *
     * @return Builder<Enrollment>
     */
    private function enrollments(): Builder
    {
        $courseId = $this->courseId();

        $query = Enrollment::query()->withoutWorkspaceScope();

        if ($courseId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('course_id', $courseId)
            /*
            | ⚠️ **صفٌّ لا يُسمّي أحداً يُسقِطُ الطلبَ كلَّه، لا صفَّه.**
            | `enrollments.student_user_id` عمودٌ بلا مفتاحٍ أجنبيٍّ على
            | المحرّكَينِ معاً، فحسابٌ يزولُ يترُكُ تسجيلَه قائماً — و
            | `$record->student->email` عندئذٍ خطأٌ يُفرِغُ الشاشةَ بأكملِها لا
            | صفّاً منها. قِيسَ في `ListStudentBalances` (٠٢٩ · `T057`) بالمشي
            | على المنتَجِ لا باختبار: اختفى كلُّ طلابِ المساحةِ من لوحةِ
            | المدرّسِ بسببِ صفٍّ واحدٍ لا يُسمّي أحداً.
            |
            | وهو **شرطٌ على الاستعلامِ لا في الصفّ**: استعلامٌ فرعيٌّ واحدٌ في
            | الجملةِ نفسِها، فلا تنمو الكلفةُ بالصفوف — وهو كذلك ما يجعلُ
            | النوعَ غيرَ النَّوّالِ في `student` صادقاً بعدَه.
            */
            ->whereHas('student')
            /*
            | ⚠️ استعلامٌ فرعيٌّ مرتبط، **لا `with()`**. لا علاقةَ مُعلَنةً بينَ
            | `Enrollment` وجدولِ العضويّاتِ تُطابِقُ المعنى — المفتاحُ مركَّبٌ
            | (الطالبُ **و**الكورس) — فضمٌّ مُسبَقٌ على أحدِ شطرَيه يردُّ صفوفاً
            | خاطئةً بصمت. وهو صفٌّ واحدٌ لكلِّ صفٍّ من صفحةٍ واحدة، لا استعلامٌ
            | لكلِّ صفّ.
            */
            ->addSelect(['current_cohort_name' => Cohort::query()
                ->withoutWorkspaceScope()
                ->select('cohorts.name')
                ->join('cohort_memberships', 'cohort_memberships.cohort_id', '=', 'cohorts.id')
                ->whereColumn('cohort_memberships.student_user_id', 'enrollments.student_user_id')
                ->whereColumn('cohort_memberships.course_id', 'enrollments.course_id')
                ->whereNull('cohort_memberships.closed_at')
                ->limit(1),
            ])
            // ⚠️ التجاوزُ **داخلَ الضمِّ المُسبَق** كذلك. `users` مملوكٌ للمنصّةِ
            // ولا نطاقَ عليه، فالضمُّ هنا بلا شرطٍ إضافيّ — ومكتوبٌ أنّه مقصود،
            // لأنّ إضافةَ ضمٍّ لنموذجٍ مُنطَّقٍ لاحقاً بلا تجاوزٍ هي الطبقةُ التي
            // لا ترفعُ رمزَ حالة.
            ->with(['student']);
    }

    /**
     * المجموعاتُ الصالحةُ للإسنادِ في هذا الكورس — محمَّلةً مرّةً واحدة.
     *
     * @return array<string, string>
     */
    private function assignableCohorts(): array
    {
        if ($this->cohortOptions !== null) {
            return $this->cohortOptions;
        }

        $courseId = $this->courseId();

        // ⚠️ مُفوَّضةٌ إلى الدليل: بابُ الاعتمادِ يسألُ السؤالَ نفسَه، وإملاءانِ
        // لشرطٍ واحدٍ يفترقانِ عندَ أوّلِ تعديلٍ لمعنى «صالحة للإسناد» (FR-030).
        return $this->cohortOptions = $courseId === null
            ? []
            : app(CohortDirectory::class)->assignableOptionsFor($courseId);
    }

    private function courseId(): ?int
    {
        $raw = $this->data['course'] ?? null;

        return $raw === null || $raw === '' ? null : (int) $raw;
    }

    private function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('لا مستخدم في الجلسة.');
        }

        return $user;
    }
}
