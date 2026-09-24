<?php

declare(strict_types=1);

use App\Filament\Resources\EnrollmentResource\Pages\EditEnrollment;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| «ملغى» لا يُكتَبُ من شاشةِ التسجيل في `/admin`.
|
| الإلغاءُ يمرُّ بـ«عكس الدفعة» (`ReverseCourseOrder`) لأنّه وحدَه يُطلِقُ
| `CourseAccessWithdrawn` فيُحرِّرُ المقاعدَ ويُخرِجُ الطالبَ من مجموعته. كتابةُ
| العمودِ من هنا تتركُ الاثنين معلّقَين دونَ أثر.
|
| ⚠️ مساحتا عمل، والموظَّفُ يملكُ إحداهما ويعدِّلُ في الأخرى — بلا ذلك يكونُ
| سياقُه `null` ولا يُقاسُ تجاوزُ النطاقِ في `getEloquentQuery()` أصلاً.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$home] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$away, $teacher] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $course = app(WorkspaceContext::class)->forWorkspace(
        $away,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $away->getKey(),
            'created_by' => $teacher->getKey(),
        ]),
    );

    $this->makeEnrollment = fn (string $status): Enrollment => Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $away->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => User::factory()->create()->getKey(),
        'source' => 'purchase',
        'status' => $status,
        'enrolled_at' => now(),
    ]);

    // `last_workspace_id` في `$guarded` — `create([...])` يُسقِطُه بصمت.
    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $home->getKey()])->save();

    $this->actingAs($this->officer);
});

function editEnrollmentPage(Enrollment $enrollment): mixed
{
    return Livewire::test(EditEnrollment::class, ['record' => $enrollment->getRouteKey()]);
}

function statusOnDisk(Enrollment $enrollment): string
{
    return (string) Enrollment::query()->withoutWorkspaceScope()->whereKey($enrollment->getKey())->value('status');
}

it('does not offer cancelled on an active enrolment', function (): void {
    $enrollment = ($this->makeEnrollment)('active');

    editEnrollmentPage($enrollment)
        ->assertFormFieldExists('status', function (Select $field): bool {
            $options = $field->getOptions();

            return ! array_key_exists('cancelled', $options)
                && array_key_exists('active', $options)
                && array_key_exists('completed', $options)
                && array_key_exists('expired', $options);
        })
        ->assertFormFieldEnabled('status');
});

it('refuses a forged save that sets cancelled and leaves the row unchanged', function (): void {
    $enrollment = ($this->makeEnrollment)('active');

    editEnrollmentPage($enrollment)
        ->fillForm(['status' => 'cancelled'])
        ->call('save')
        ->assertHasFormErrors(['status']);

    expect(statusOnDisk($enrollment))->toBe('active');
});

/*
| ⚠️ The forged save above is refused by the Select's own `in` rule first, so
| deleting the page guard leaves it green (measured). This case drives the guard
| directly — it is what stands if the option list is ever widened again.
*/
it('refuses a transition into cancelled at the save handler itself', function (): void {
    $enrollment = ($this->makeEnrollment)('active');

    $page = editEnrollmentPage($enrollment)->instance();
    $guard = new ReflectionMethod($page, 'mutateFormDataBeforeSave');

    expect(fn () => $guard->invoke($page, ['status' => 'cancelled']))
        ->toThrow(ValidationException::class, 'عكس الدفعة');

    expect($guard->invoke($page, ['status' => 'expired']))->toBe(['status' => 'expired']);
});

it('keeps an already-cancelled enrolment displayed and locked', function (): void {
    $enrollment = ($this->makeEnrollment)('cancelled');

    editEnrollmentPage($enrollment)
        ->assertFormSet(['status' => 'cancelled'])
        ->assertFormFieldExists('status', fn (Select $field): bool => array_key_exists('cancelled', $field->getOptions()))
        ->assertFormFieldDisabled('status')
        ->fillForm(['status' => 'active'])
        ->call('save');

    // A reversed enrolment is reopened by a new purchase, never by hand.
    expect(statusOnDisk($enrollment))->toBe('cancelled');
});

it('still saves the other status transitions', function (): void {
    $enrollment = ($this->makeEnrollment)('active');

    editEnrollmentPage($enrollment)
        ->fillForm(['status' => 'expired'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(statusOnDisk($enrollment))->toBe('expired');
});
