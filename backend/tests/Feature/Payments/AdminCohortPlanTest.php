<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/*
| ٠٣٦ · T087/T088 — الإدارةُ تكتبُ باقةً تخصُّ مجموعةً بعينِها، باسمِ مدرّس.
|
| ⛔ **الموظَّفُ يملكُ مساحةَ عملٍ أخرى، وهذا هو السطرُ الذي يُقاسُ به أيُّ شيء.**
| `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id` للموظَّفِ كأيِّ
| مستخدمٍ آخر، فتركيبةٌ بمساحةٍ واحدةٍ تجعلُ المُنطَّقَ والمُتجاوِزَ متّفقَين
| تماماً — وهي التركيبةُ التي أخفَت خمسَ طبقاتٍ من عطبِ ٠٢٤.
|
| ⛔ **وتُقادُ الصفحةُ نفسُها بالانعكاس، لا الـAction.** الفخُّ يعيشُ في
| `handleRecordCreation()`: الافتراضيُّ في Filament إسنادٌ شامل، فيكتبُ الصفَّ
| في مساحةِ الموظَّفِ ويُسقِطُ السعرَ صامتاً ولا يحلُّ التغطية. نداءُ الـAction
| مباشرةً لا يمرُّ بذلك السطرِ إطلاقاً — كما تقولُ جارتُها
| `AdminCreatesPlanForTeacherTest`.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->officerWorkspace, $this->officer] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    makePlatformStaff(Roles::FINANCE_ADMIN, $this->officer);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    [$this->course, $this->cohort] = app(WorkspaceContext::class)->forWorkspace(
        $this->teacherWorkspace,
        function (): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $this->teacherWorkspace->getKey(),
                'created_by' => $this->teacher->getKey(),
                'course_type' => Course::TYPE_GROUP,
                'title' => 'التفاضل',
            ]);

            return [$course, Cohort::factory()->create([
                'workspace_id' => $this->teacherWorkspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $this->teacher->getKey(),
                'name' => 'مجموعة الجمعة',
            ])];
        },
    );

    $this->officer->refresh();
    app(WorkspaceContext::class)->set($this->officerWorkspace);
});

it('writes a group-scoped plan into the teacher\'s workspace, not the officer\'s', function (): void {
    Livewire::actingAs($this->officer)
        ->test(CreatePlan::class)
        ->fillForm([
            'teacher' => (string) $this->teacherWorkspace->uuid,
            'title' => 'الجمعة المكثّفة',
            'shape' => 'duration',
            'duration_days' => 30,
            'session_type' => ClassSessionType::Group->value,
            'coverage_type' => PlanCoverage::Cohort->value,
            'coverage_uuid' => (string) $this->cohort->uuid,
            'price_minor' => 60_000,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = Plan::query()->withoutWorkspaceScope()->where('title', 'الجمعة المكثّفة')->first();

    expect($plan)->not->toBeNull()
        /*
        | ⛔ THE TEACHER'S WORKSPACE, AND THIS IS THE ASSERTION THE PAGE EXISTS
        | FOR. `BelongsToWorkspace` auto-fills from the WRITER's context, so the
        | default create path writes the officer's own id here — a plan that shows
        | up in the officer's catalogue and never in the teacher's, with no error
        | anywhere.
        */
        ->and((int) $plan->workspace_id)->toBe((int) $this->teacherWorkspace->getKey())
        ->and($plan->coverage_type)->toBe(PlanCoverage::Cohort)
        ->and($plan->coverage_uuid)->toBe((string) $this->cohort->uuid)
        // ⚠️ AND THE PRICE LANDED. It is deliberately not `$fillable`, and mass
        // assignment DISCARDS a non-fillable key in silence — a green toast over
        // a null column.
        ->and((int) $plan->price_minor)->toBe(60_000);
});

it('offers the chosen teacher\'s groups, never the officer\'s own', function (): void {
    // مجموعةٌ في مساحةِ الموظَّفِ نفسِه — هي ما يعرِضُه منتقٍ مبنيٌّ على الكاتب.
    $officerCohort = app(WorkspaceContext::class)->forWorkspace(
        $this->officerWorkspace,
        function (): Cohort {
            $course = Course::factory()->published()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'created_by' => $this->officer->getKey(),
                'course_type' => Course::TYPE_GROUP,
                'title' => 'كورس الموظّف',
            ]);

            return Cohort::factory()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $this->officer->getKey(),
                'name' => 'مجموعة الموظّف',
            ]);
        },
    );

    $options = [];

    Livewire::actingAs($this->officer)
        ->test(CreatePlan::class)
        ->fillForm([
            'teacher' => (string) $this->teacherWorkspace->uuid,
            'coverage_type' => PlanCoverage::Cohort->value,
        ])
        ->assertFormFieldExists('coverage_uuid', function (Select $field) use (&$options): bool {
            $options = $field->getOptions();

            return true;
        });

    /*
    | ⛔ THE PICKER IS BUILT FROM THE CHOSEN TEACHER. An officer is a member of no
    | teacher's workspace, so a scoped picker offers their own groups or nothing —
    | and a plan pointed at the wrong group opens a course the buyer never asked
    | for. The label carries the course because one teacher may have «مجموعة
    | السبت» in two of them.
    */
    expect(array_keys($options))->toBe([(string) $this->cohort->uuid])
        ->and($options[(string) $this->cohort->uuid])->toBe('التفاضل — مجموعة الجمعة')
        ->and($options)->not->toHaveKey((string) $officerCohort->uuid);
});

it('refuses a group that belongs to somebody else', function (): void {
    /*
    | ⛔ HIDING A CONTROL IS NOT A GUARD. The picker above offers only the chosen
    | teacher's groups; the form state still travels from a browser and is written
    | as it is clicked, so `SavePlan` proves the group against the teacher's
    | workspace again. Without that, an officer with another teacher's group uuid
    | writes a plan that opens a course in a workspace nobody chose.
    */
    $stranger = app(WorkspaceContext::class)->forWorkspace(
        $this->officerWorkspace,
        function (): Cohort {
            $course = Course::factory()->published()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'created_by' => $this->officer->getKey(),
                'course_type' => Course::TYPE_GROUP,
            ]);

            return Cohort::factory()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $this->officer->getKey(),
                'name' => 'مجموعة غريبة',
            ]);
        },
    );

    $before = Plan::query()->withoutWorkspaceScope()->count();

    Livewire::actingAs($this->officer)
        ->test(CreatePlan::class)
        ->fillForm([
            'teacher' => (string) $this->teacherWorkspace->uuid,
            'title' => 'باقة مسروقة',
            'shape' => 'duration',
            'duration_days' => 30,
            'session_type' => ClassSessionType::Group->value,
            'coverage_type' => PlanCoverage::Cohort->value,
            'coverage_uuid' => (string) $stranger->uuid,
            'price_minor' => 60_000,
            'is_active' => true,
        ])
        ->call('create');

    expect(Plan::query()->withoutWorkspaceScope()->count())->toBe($before);
});
