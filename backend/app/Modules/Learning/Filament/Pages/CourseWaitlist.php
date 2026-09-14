<?php

declare(strict_types=1);

namespace App\Modules\Learning\Filament\Pages;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Learning\Actions\InviteFromWaitlist;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CourseWaitlistEntry;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use stdClass;
use UnitEnum;

/**
 * دَورُ كورسٍ مكتمل، كما تقرؤُه الإدارة (٠٣٤ · FR-027 · FR-029).
 *
 * ⚠️ **والشاشةُ تقولُ صراحةً إنّ الدَّورَ لا يحجزُ مقعداً ولا يَعِدُ به.** رقمٌ
 * مرسومٌ بلا هذه الجملةِ يُقرَأُ حجزاً — والموظَّفُ هو من سيُسأَلُ عنه بعدَ شهر.
 *
 * ⚠️ **والرقمُ من فهرسِ الصفِّ في الصفحة، لا من عدٍّ لكلِّ صفّ.** «كم قبلي» مكتوبةً
 * استعلاماً لكلِّ صفٍّ هي `N+1` بالبناءِ على أسرعِ جداولِ المرحلةِ نموّاً — عينُ
 * عطبِ `ClassSessionResource` من بابٍ جديد. والترتيبُ **وقتٌ ثمّ معرِّف**: تسجيلانِ
 * في الثانيةِ نفسِها يتساويانِ في العمودِ الأوّلِ ويفصلُهما الثاني، وبدونِه
 * يتبدّلُ ترتيبُهما بينَ فتحةٍ وأخرى للصفحةِ نفسِها.
 *
 * ⚠️ **والكورسُ شرطٌ لا مُرشِّح.** لائحةٌ افتراضُها «الكلّ» مسحٌ لجدولِ الدَّورِ
 * كلِّه عبرَ كلِّ المساحات، وهي كذلك تُسقِطُ العمودَ الأوّلَ من الفهرسِ المركَّبِ
 * الذي كُتِبَ لهذه الشاشةِ بعينِها.
 *
 * ⚠️ **و`canAccess()` تسألُ الصلاحيّةَ صراحةً، وكلُّ كتابةٍ تسألُها ثانية.** صفحةُ
 * Filament **لا تستدعي سياسةً إطلاقاً**، وقَبولُ اللوحةِ نفسِها «مديرُ منصّةٍ أو
 * أيُّ صفٍّ في `platform_staff`» — ومسؤولُ الامتثالِ منهم ولا يحملُ من هذا شيئاً.
 * **وهي شاشةٌ تحملُ أسماءَ طلابٍ عبرَ كلِّ المساحات**، فحراستُها ليست شكليّة.
 * ولا صلاحيّةَ ثانية: مَن يُسنِدُ إلى مجموعةٍ هو مَن يدعو إليها.
 *
 * ⚠️ **وكلُّ قراءةٍ تُعلِنُ تجاوزَ النطاق، ويُكرَّرُ التجاوزُ داخلَ كلِّ ضمٍّ
 * مُسبَق** — التجاوزُ لكلِّ نموذجٍ لا للاستعلامِ كلِّه، وهي طبقةُ ٠٢٤ الخامسةُ
 * التي لا ترفعُ رمزَ حالةٍ إطلاقاً.
 *
 * @property-read Schema $form
 */
class CourseWaitlist extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.course-waitlist';

    protected static ?string $slug = 'course-waitlist';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 32;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::COHORTS_ASSIGN) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'دَور الانتظار';
    }

    public function getTitle(): string
    {
        return 'دَور الانتظار على كورس';
    }

    /**
     * ⚠️ `$data` يبدأُ `[]`، وحقلُ Filament المبحوثُ يربطُ نفسَه بـ
     * `$wire.entangle('data.course')` — و**entangle يشترطُ وجودَ الخاصّيّةِ
     * سلفاً**. بلا هذه الجملةِ يرسمُ المتصفّحُ الكورسَ مختاراً ولا تصلُ قيمتُه
     * الخادمَ أبداً.
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
                        ->description('الدَّورُ يُقرَأُ لكورسٍ واحدٍ في كلِّ مرّة.')
                        ->schema([
                            // ⚠️ `withoutWorkspaceScope()` — الموظَّفُ ليسَ عضواً في
                            // مساحةِ أيِّ مدرّس، وسياقُه يرجعُ إلى
                            // `last_workspace_id` إن كانَ يملكُ مساحةً هو الآخر.
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
            ->query($this->entries())
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading($this->courseId() === null ? 'اختَرِ الكورسَ أوّلاً' : 'لا أحدَ في الدَّور')
            ->emptyStateDescription($this->courseId() === null
                ? 'الشاشةُ تعملُ على كورسٍ واحدٍ في كلِّ مرّة.'
                : 'لم يسجّلْ أحدٌ في دَورِ هذا الكورس، أو خرجَ من سجَّلَ حينَ صارَ له تسجيل.')
            ->columns([
                /*
                | ⚠️ الرقمُ من فهرسِ الصفِّ في الصفحةِ لا من عدٍّ لكلِّ صفّ —
                | انظرْ وصفَ الصنف. و`$rowLoop` تعرِفُ موضعَها في **الصفحة**،
                | فيُضافُ إزاحةُ الصفحةِ إليها.
                */
                TextColumn::make('position')
                    ->label('الموضع')
                    ->state(fn (stdClass $rowLoop): int => (int) $rowLoop->iteration
                        + (((int) $this->getTablePage() - 1) * (int) $this->getTableRecordsPerPage())),

                // ⚠️ بلا تقييدِ أعمدة: `name` سِمةٌ مشتقّةٌ من `first_name`/`last_name`،
                // فضمٌّ مُسبَقٌ يسمّي الأعمدةَ يرسمُ اسماً فارغاً لكلِّ صفٍّ بلا خطأ.
                TextColumn::make('student.name')->label('الطالب')->placeholder('—')
                    ->description(fn (CourseWaitlistEntry $record): string => (string) $record->student->email),

                TextColumn::make('created_at')->label('سجَّل في')->dateTime('Y-m-d H:i')->sortable(),

                TextColumn::make('invited_at')
                    ->label('دُعي')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يُدعَ بعد')
                    ->badge()
                    ->color(fn (mixed $state): string => $state === null ? 'gray' : 'success'),
            ])
            ->headerActions([
                $this->inviteAction(),
            ]);
    }

    /**
     * فتحُ مقاعدَ ودعوةُ أوائلِ الدَّورِ إليها.
     *
     * ⚠️ **الصلاحيّةُ تُسأَلُ ثانيةً هنا** ولا يُكتفى بـ`canAccess()`: بينَ فتحِ
     * الصفحةِ وضغطِ الزرِّ جلسةٌ كاملة، وسحبُ صلاحيّةٍ يجبُ أن يُوقِفَ الكتابةَ
     * التالية. وإخفاءُ زرٍّ ليسَ حراسة.
     */
    private function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('ادعُ من الدَّور')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->color('primary')
            ->visible(fn (): bool => $this->courseId() !== null)
            ->modalHeading('دعوةُ أوائلِ الدَّور')
            ->modalDescription('يُدعى بقدرِ المقاعدِ الشاغرةِ في المجموعةِ المختارة، بالترتيب. '
                .'ومَن لم يُدعَ يبقى مكانَه ولا يصلُه شيء.')
            ->schema([
                Select::make('cohort')
                    ->label('المجموعة')
                    ->required()
                    ->options(fn (): array => $this->cohortOptions())
                    ->helperText('العددُ بينَ قوسَينِ هو المقاعدُ الشاغرة.'),
            ])
            ->action(function (array $data): void {
                $this->invite($data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function invite(array $data): void
    {
        $officer = Auth::user();

        if (! $officer instanceof User || ! $officer->can(Permissions::COHORTS_ASSIGN)) {
            Notification::make()->danger()->title('لم تعُدْ تملكُ إسنادَ الطلاب.')->persistent()->send();

            return;
        }

        // ⚠️ والتوثيقُ الثنائيُّ يُسأَلُ لأنّ `/admin` لا يمرُّ من `2fa.required`
        // (FR-009) — وهذه كتابةٌ تُرسِلُ رسائلَ باسمِ المنصّةِ إلى طلابِ مدرّسٍ آخر.
        if (($refusal = TwoFactorMandate::refusalFor($officer)) !== null) {
            Notification::make()->danger()->title('التحقّق بخطوتين مطلوب')->body($refusal)->persistent()->send();

            return;
        }

        // ⚠️ بالمعرِّفِ العلنيِّ **ومعه شرطُ الكورس**: خيارُ النموذجِ يُكتَبُ كما
        // يُنقَر، ومعرِّفُ مجموعةٍ من كورسٍ آخرَ يدعو دَورَ كورسٍ إلى غرفةِ آخر.
        $cohort = Cohort::query()->withoutWorkspaceScope()
            ->where('uuid', $data['cohort'] ?? null)
            ->where('course_id', $this->courseId())
            ->first();

        if (! $cohort instanceof Cohort) {
            Notification::make()->danger()->title('اختيارٌ ناقص، لم يُكتَبْ شيء.')->send();

            return;
        }

        $invited = app(InviteFromWaitlist::class)->handle($cohort, $officer);

        if ($invited === 0) {
            Notification::make()->warning()
                ->title('لا مقعدَ شاغراً في «'.$cohort->name.'»، أو لا أحدَ ينتظرُ دعوةً.')
                ->send();

            return;
        }

        Notification::make()->success()->title('دُعي '.$invited.' من الدَّور.')->send();
    }

    /**
     * صفوفُ الدَّورِ القائمةِ لهذا الكورس — ولا شيءَ قبلَ اختيارِه.
     *
     * ⚠️ الحاجزُ في الاستعلامِ لا في ظهورِ الجدول: Filament يبني الجدولَ عندَ
     * التركيب، فشرطٌ مكتوبٌ في `visible()` يُخفي الصفحةَ وقد نُفِّذَ المسحُ بالفعل.
     *
     * @return Builder<CourseWaitlistEntry>
     */
    private function entries(): Builder
    {
        $courseId = $this->courseId();

        $query = CourseWaitlistEntry::query()->withoutWorkspaceScope();

        if ($courseId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('course_id', $courseId)
            ->where('closed_slot', 0)
            /*
            | ⚠️ **صفٌّ لا يُسمّي أحداً يُسقِطُ الطلبَ كلَّه، لا صفَّه.**
            | `student_user_id` عمودٌ بلا مفتاحٍ أجنبيٍّ على المحرّكَين، فحسابٌ
            | يزولُ يترُكُ صفَّه قائماً — و`$record->student->email` عندئذٍ خطأٌ
            | يُفرِغُ الشاشةَ بأكملِها. قِيسَ في `ListStudentBalances` بالمشي على
            | المنتَجِ لا باختبار.
            */
            ->whereHas('student')
            ->with(['student'])
            // وقتٌ ثمّ معرِّف — انظرْ وصفَ الصنف.
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * ⚠️ **المجموعاتُ الصالحةُ للإسنادِ وحدَها**، والعددُ الشاغرُ في التسمية:
     * دعوةٌ إلى مجموعةٍ مؤرشَفةٍ أو مكتمِلةٍ رسالةٌ لا يستطيعُ قارئُها أن يفعلَ
     * بها شيئاً.
     *
     * @return array<string, string>
     */
    private function cohortOptions(): array
    {
        $courseId = $this->courseId();

        if ($courseId === null) {
            return [];
        }

        return Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $courseId)
            ->group()
            ->assignable()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (Cohort $cohort): array => [
                (string) $cohort->uuid => $cohort->name.($cohort->seatsLeft() === null
                    ? ' — بلا حدّ' : ' — '.$cohort->seatsLeft()),
            ])
            ->all();
    }

    private function courseId(): ?int
    {
        $value = $this->data['course'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
