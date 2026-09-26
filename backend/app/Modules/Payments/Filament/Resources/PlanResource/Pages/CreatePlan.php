<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\PlanResource\Pages;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\CreatePlanForTeacher;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\CohortDirectory;
use DomainException;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * البابُ الثاني لإنشاءِ باقة: الإدارةُ تُنشئُها باسمِ مدرّسٍ منصوصٍ عليه
 * (٠٣٤ · FR-017 … FR-019).
 *
 * ⛔ **و`handleRecordCreation()` تُستبدَلُ كاملةً — وبدونِها تُكتَبُ الباقةُ في
 * مساحةِ الموظَّفِ نفسِه بلا خطأٍ واحد.** الافتراضيُّ في Filament هو
 * `new Plan($data)` ثمّ `save()`: فـ`BelongsToWorkspace` يملأُ `workspace_id`
 * من سياقِ الكاتب — وهو الموظَّفُ لا المدرّس، وهذا بعينُه ما تمنعُه FR-018 —
 * و`price_minor` **غيرُ قابلٍ للإسنادِ فيُسقَطُ صامتاً**، ولا تُحَلُّ التغطيةُ،
 * ولا يُرفَضُ مدرّسٌ غادرَ المنصّة، ولا يصلُ أحداً إشعار. نموذجٌ يبدو صحيحاً
 * ويكتبُ صفّاً خاطئاً في مكانٍ خاطئ.
 *
 * ⚠️ **ونموذجُ هذه الصفحةِ نموذجُها هي، لا نموذجُ المورد.** حقولُ المدرّسِ في
 * نموذجِ التحريرِ `Placeholder`s بنصِّ وصفِها — عرضٌ للتسعيرِ على أساسِه لا
 * تحرير — فلا شيءَ فيها يُكتَبُ منه.
 *
 * ⚠️ **والمُنتقي لا يُصفّي المدرّسين، والفعلُ هو الذي يرفض.** «مساحةٌ صالحةٌ»
 * ثلاثةُ شروطٍ ({@see CreatePlanForTeacher})، وإملاءُها ثانيةً هنا إملاءانِ
 * لسؤالٍ واحدٍ يفترقانِ عندَ أوّلِ تعديل — والرفضُ يصلُ الموظَّفَ بجملةٍ تقولُ
 * أيَّ الشروطِ سقط، وهو أنفعُ من اسمٍ غائبٍ عن قائمةٍ بلا سبب.
 */
class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    public function getTitle(): string
    {
        return 'إنشاء باقة باسم مدرّس';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المدرّس')
                ->description('الباقةُ تُكتَبُ في مساحةِ هذا المدرّسِ وتُباعُ باسمِه، ويصلُه إشعارٌ بها. '
                    .'ولا يُستنتَجُ من مساحتِك أنت (FR-018).')
                ->columns(1)
                ->schema([
                    /*
                    | ⚠️ بالمعرِّفِ العلنيِّ لا بالمفتاحِ الرقميّ: خيارُ النموذجِ
                    | يمرُّ من المتصفّحِ ويُكتَبُ كما يُنقَر، و`HasUuid` هو ما
                    | تعرِضُه هذه الشجرةُ في كلِّ حمولة (درسُ `T013` في هذه
                    | المرحلةِ نفسِها).
                    */
                    Select::make('teacher')
                        ->label('المدرّس (مساحة العمل)')
                        ->required()
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search): array => Workspace::query()
                            ->where('name', 'like', "%{$search}%")
                            ->limit(20)
                            ->pluck('name', 'uuid')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Workspace::query()
                            ->where('uuid', $value)->value('name'))
                        ->helperText('ابحثْ باسمِ مساحةِ المدرّس.'),
                ]),

            Section::make('ما يكتبه المدرّس عادةً')
                ->description('المدّةُ والنوعُ والتغطيةُ — تُكتَبُ هنا بالنيابة، ويبقى للمدرّسِ تعديلُها '
                    .'من شاشتِه كأيِّ باقةٍ أنشأَها بنفسِه (FR-022).')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label('عنوان الباقة')
                        ->required()
                        ->maxLength(255),

                    /*
                    | 036 . T071 -- THE SHAPE IS PICKED, AND WITHOUT IT NO OFFICER
                    | COULD WRITE AN HOURS PLAN AT ALL. The duration was
                    | `required()->minValue(1)`, so the form refused to submit
                    | without one; and `SavePlan` refuses a row carrying both, so
                    | a second always-visible field would have made every save
                    | fail from the other side.
                    |
                    | It is a picker rather than «fill whichever you mean» because
                    | one of the two IS the decision -- and a form that lets both
                    | be typed is a form whose only error message arrives after
                    | the save.
                    */
                    Select::make('shape')
                        ->label('ما تبيعه الباقة')
                        ->required()
                        ->live()
                        ->default('duration')
                        ->options([
                            'duration' => 'مدّة بالأيّام',
                            'sessions' => 'عدد من الحصص',
                        ])
                        ->helperText('باقةُ الحصصِ تصبُّ رصيداً في دفترِ الطالب ولا تكتبُ اشتراكاً، '
                            .'ولا بدَّ أن تخصَّ كورساً أو مجموعة.'),

                    TextInput::make('duration_days')
                        ->label('المدّة بالأيّام')
                        ->numeric()
                        ->required(fn (callable $get): bool => $get('shape') !== 'sessions')
                        ->visible(fn (callable $get): bool => $get('shape') !== 'sessions')
                        ->minValue(1)
                        ->default(30),

                    TextInput::make('session_count')
                        ->label('عدد الحصص')
                        ->numeric()
                        ->required(fn (callable $get): bool => $get('shape') === 'sessions')
                        ->visible(fn (callable $get): bool => $get('shape') === 'sessions')
                        ->minValue(1),

                    Select::make('session_type')
                        ->label('نوع الحصص')
                        ->required()
                        ->options(fn (): array => collect(ClassSessionType::cases())
                            ->mapWithKeys(fn (ClassSessionType $type): array => [$type->value => $type->label()])
                            ->all()),

                    Select::make('coverage_type')
                        ->label('التغطية')
                        ->required()
                        ->live()
                        ->default(PlanCoverage::Workspace->value)
                        ->options(fn (): array => collect(PlanCoverage::cases())
                            ->mapWithKeys(fn (PlanCoverage $coverage): array => [$coverage->value => $coverage->label()])
                            ->all()),

                    /*
                    | ⚠️ كورساتُ **المدرّسِ المختارِ** وحدَه، وبتجاوزِ النطاق:
                    | الموظَّفُ ليسَ عضواً في مساحتِه، فقائمةٌ مقيَّدةٌ بالنطاقِ
                    | تعرِضُ كورساتِ الموظَّفِ أو لا شيء. والشرطُ الصريحُ بمساحةِ
                    | المدرّسِ هو الحارس — وهو نفسُه ما يعيدُ `SavePlan` فحصَه.
                    */
                    /*
                    | ⛔ 036 . T087 -- IT ALSO OFFERS THE TEACHER'S GROUPS, and the
                    | list comes from the CHOSEN TEACHER, never from the writer.
                    | On this door the writer is a platform officer who is a member
                    | of no teacher's workspace, so a scoped picker shows their own
                    | groups or nothing at all -- the mirror of what `coursesOf()`
                    | below already writes down for courses.
                    |
                    | One field for both narrow coverages rather than two, because
                    | the column IS one: `coverage_uuid` holds whichever public
                    | identifier the coverage names, and a second field would be a
                    | second writer for it.
                    */
                    Select::make('coverage_uuid')
                        ->label(fn (callable $get): string => $get('coverage_type') === PlanCoverage::Cohort->value
                            ? 'المجموعة'
                            : 'الكورس')
                        ->options(fn (callable $get): array => $get('coverage_type') === PlanCoverage::Cohort->value
                            ? $this->cohortsOf($get('teacher'))
                            : $this->coursesOf($get('teacher')))
                        ->searchable()
                        ->required(fn (callable $get): bool => self::namesOne($get('coverage_type')))
                        ->visible(fn (callable $get): bool => self::namesOne($get('coverage_type')))
                        ->helperText('اختَرِ المدرّسَ أوّلاً.'),
                ]),

            Section::make('سعر المنصّة')
                ->description('بالوحدةِ الصغرى: ٣٠٠ ريال تُكتَبُ 30000. وهو مطلوبٌ هنا (FR-017): '
                    .'مَن يُنشئُ بالنيابةِ هو نفسُه مَن يُسعِّر، فباقةٌ تنتظرُ تسعيرَه هي طابورٌ إلى نفسِه.')
                ->columns(2)
                ->schema([
                    TextInput::make('price_minor')
                        ->label('السعر بالوحدة الصغرى')
                        ->numeric()
                        ->required()
                        ->minValue(0),

                    Toggle::make('is_active')
                        ->label('مفعَّلة')
                        ->default(true),
                ]),
        ]);
    }

    /**
     * ⚠️ الكتابةُ كلُّها من الفعل — انظرْ وصفَ الصنف.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $officer = Auth::user();

        $workspace = Workspace::query()->where('uuid', $data['teacher'] ?? null)->first();

        if (! $officer instanceof User || ! $workspace instanceof Workspace) {
            Notification::make()->danger()->title('اختيارٌ ناقص، لم يُكتَبْ شيء.')->send();

            throw new Halt;
        }

        $raw = $data['price_minor'] ?? null;

        try {
            return app(CreatePlanForTeacher::class)->handle(
                $officer,
                $workspace,
                [
                    'title' => $data['title'],
                    /*
                    | ⛔ THE HIDDEN FIELD IS SENT AS NULL, NOT LEFT OUT. Filament
                    | keeps the state of a field it stopped showing, so an officer
                    | who fills 30, switches to «حصص» and saves would otherwise
                    | reach the Action with BOTH -- refused with a sentence about a
                    | field the form is no longer displaying.
                    */
                    'duration_days' => ($data['shape'] ?? 'duration') === 'sessions' ? null : ($data['duration_days'] ?? null),
                    'session_count' => ($data['shape'] ?? 'duration') === 'sessions' ? ($data['session_count'] ?? null) : null,
                    'session_type' => $data['session_type'],
                    'coverage_type' => $data['coverage_type'],
                    'coverage_uuid' => $data['coverage_uuid'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ],
                $raw === null || $raw === '' ? null : (int) $raw,
            );
        } catch (DomainException $e) {
            // جملةُ الفعلِ نفسُها: كلٌّ منها تقولُ أيُّ شرطٍ سقطَ وما البديل،
            // ورسالةٌ عامّةٌ تُخفي أيَّها.
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    /**
     * Does this coverage name one thing, or the whole workspace?
     *
     * ⚠️ IT ASKS THE ENUM, NEVER A LIST OF TWO VALUES WRITTEN OUT HERE.
     * `PlanCoverage::requiresUuid()` is what `SavePlan` resolves against, and a
     * form that disagrees with it either hides a required field -- an officer who
     * cannot submit and is not told why -- or shows one the Action then ignores.
     */
    private static function namesOne(mixed $coverage): bool
    {
        return is_string($coverage)
            && PlanCoverage::tryFrom($coverage)?->requiresUuid() === true;
    }

    /**
     * The group cohorts of the chosen teacher, labelled by their course.
     *
     * ⚠️ THROUGH THE DIRECTORY, NEVER A QUERY ON `cohorts`. That table belongs to
     * `Learning` and `ContextIsolationTest` fails the build over a Payments file
     * that names it -- and the directory is already filtered to GROUP cohorts,
     * which is what stops a plan being pointed at a named student's private room.
     *
     * ⚠️ AND THE COURSE IS IN THE LABEL. A teacher with «مجموعة السبت» in two
     * courses would otherwise be shown the same words twice with no way to tell
     * which is which -- and the wrong pick writes a plan that opens a course the
     * buyer never asked for.
     *
     * @return array<string, string>
     */
    private function cohortsOf(mixed $workspaceUuid): array
    {
        $courses = $this->coursesById($workspaceUuid);

        if ($courses === []) {
            return [];
        }

        $options = [];

        foreach (app(CohortDirectory::class)->teacherCohortsFor(array_keys($courses)) as $courseId => $cohorts) {
            foreach ($cohorts as $cohort) {
                $uuid = $cohort['uuid'] ?? null;

                if (is_string($uuid) && $uuid !== '') {
                    $options[$uuid] = ($courses[$courseId] ?? '—').' — '.(string) ($cohort['name'] ?? '—');
                }
            }
        }

        asort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function coursesOf(mixed $workspaceUuid): array
    {
        if (! is_string($workspaceUuid) || $workspaceUuid === '') {
            return [];
        }

        $workspaceId = Workspace::query()->where('uuid', $workspaceUuid)->value('id');

        if ($workspaceId === null) {
            return [];
        }

        return Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->orderBy('title')
            ->pluck('title', 'uuid')
            ->all();
    }

    /**
     * The chosen teacher's courses keyed by their numeric id — what the cohort
     * directory takes, and the titles the picker's labels are built from.
     *
     * @return array<int, string>
     */
    private function coursesById(mixed $workspaceUuid): array
    {
        if (! is_string($workspaceUuid) || $workspaceUuid === '') {
            return [];
        }

        $workspaceId = Workspace::query()->where('uuid', $workspaceUuid)->value('id');

        if ($workspaceId === null) {
            return [];
        }

        return Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }
}
