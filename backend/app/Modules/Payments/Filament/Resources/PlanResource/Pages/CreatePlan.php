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

                    TextInput::make('duration_days')
                        ->label('المدّة بالأيّام')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(30),

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
                    Select::make('coverage_uuid')
                        ->label('الكورس')
                        ->options(fn (callable $get): array => $this->coursesOf($get('teacher')))
                        ->searchable()
                        ->required(fn (callable $get): bool => $get('coverage_type') === PlanCoverage::Course->value)
                        ->visible(fn (callable $get): bool => $get('coverage_type') === PlanCoverage::Course->value)
                        ->helperText('اختَرِ المدرّسَ أوّلاً.'),
                ]),

            Section::make('سعر المنصّة')
                ->description('بالوحدةِ الصغرى: ٣٠٠٫٠٠ ريالاً تُكتَبُ 30000. وهو مطلوبٌ هنا (FR-017): '
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
                    'duration_days' => $data['duration_days'],
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
}
