<?php

declare(strict_types=1);

use App\Filament\Resources\ExamResource;
use App\Filament\Resources\ExamResource\Pages\EditExam;
use App\Filament\Resources\ExamResource\Pages\ListExams;
use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| The exams screen in `/admin` — who opens it, what it lists, and the doors
| that deleted or re-statused a paper without any rule reaching them.
|
| ⚠️ TWO WORKSPACES AND A READER WHO BELONGS TO ONE OF THEM, or none of this
| proves anything. `WorkspaceContext::id()` falls back to
| `users.last_workspace_id` for platform staff as for anybody, and every defect
| below is invisible to a reader with no workspace.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة القارئ']);
    [$this->other, $this->otherOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة مدرّس آخر']);

    $this->otherCourse = app(WorkspaceContext::class)->forWorkspace(
        $this->other,
        fn (): Course => Course::factory()->create(['workspace_id' => $this->other->getKey()]),
    );
    $this->homeCourse = app(WorkspaceContext::class)->forWorkspace(
        $this->home,
        fn (): Course => Course::factory()->create(['workspace_id' => $this->home->getKey()]),
    );

    $this->exam = app(WorkspaceContext::class)->forWorkspace(
        $this->other,
        fn (): Exam => Exam::factory()->create([
            'workspace_id' => $this->other->getKey(),
            'course_id' => $this->otherCourse->getKey(),
            'status' => 'draft',
        ]),
    );

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** Sign in as `$user` with their own workspace resolved, the way the panel's middleware does. */
function examPanelAs(User $user, ?Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);

    if ($home !== null) {
        test()->setCurrentWorkspace($home, $user);
    }
}

function examPanelAttempt(Exam $exam): void
{
    DB::table('exam_attempts')->insert([
        'workspace_id' => $exam->workspace_id,
        'uuid' => (string) Str::uuid(),
        'exam_id' => $exam->getKey(),
        'student_user_id' => User::factory()->create()->getKey(),
        'status' => 'graded',
        'random_seed' => 1,
        'attempt_number' => 1,
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens for the super admin only — never for platform staff who own a workspace', function (string $role): void {
    /*
    | ⚠️ THE OFFICER OWNS A WORKSPACE, SO THEY HOLD `exams.*` THERE. That is the
    | whole reason no `Permissions::` constant can gate a platform-wide list of
    | exams: a tenant permission held in one's own workspace would open every
    | teacher's papers.
    */
    $officer = makePlatformStaff($role, $this->homeOwner);
    examPanelAs($officer, $this->home);

    expect($officer->can(Permissions::EXAMS_VIEW))->toBeTrue()
        ->and(ExamResource::canViewAny())->toBeFalse()
        ->and(ExamResource::canEdit($this->exam))->toBeFalse()
        ->and(ExamResource::canDelete($this->exam))->toBeFalse();
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER],
]);

it('lists every workspace for a super admin who has one, with the counts and the course', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    examPanelAs($admin, $this->home);
    examPanelAttempt($this->exam);

    $row = ExamResource::getEloquentQuery()->whereKey($this->exam->getKey())->first();

    expect(ExamResource::canViewAny())->toBeTrue()
        ->and($row)->not->toBeNull()
        ->and($row?->getAttribute('attempts_count'))->toBe(1)
        ->and($row?->getRelationValue('course')?->getKey())->toBe($this->otherCourse->getKey());
});

it('offers no bulk delete and no create button', function (): void {
    examPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    expect(ExamResource::canDeleteAny())->toBeFalse()
        ->and(ExamResource::canCreate())->toBeFalse();

    Livewire::test(ListExams::class)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertActionDoesNotExist('create');
});

it('refuses to delete a paper somebody sat — from the panel and from the API', function (): void {
    examPanelAttempt($this->exam);
    examPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    Livewire::test(EditExam::class, ['record' => $this->exam->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Exam::query()->withoutWorkspaceScope()->whereKey($this->exam->getKey())->exists())->toBeTrue();

    // The refusal is on the model, so the teacher's own door answers it too.
    Sanctum::actingAs($this->otherOwner);
    app()->forgetInstance(WorkspaceContext::class);
    $this->setCurrentWorkspace($this->other, $this->otherOwner);

    $this->deleteJson("/api/v1/exams/{$this->exam->uuid}")->assertStatus(422);

    expect(Exam::query()->withoutWorkspaceScope()->whereKey($this->exam->getKey())->exists())->toBeTrue();
});

it('still deletes a paper nobody sat', function (): void {
    examPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    Livewire::test(EditExam::class, ['record' => $this->exam->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Exam::query()->withoutWorkspaceScope()->whereKey($this->exam->getKey())->exists())->toBeFalse();
});

it('publishes through the Action, so the activity log records it', function (): void {
    examPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    Livewire::test(EditExam::class, ['record' => $this->exam->getRouteKey()])
        ->fillForm(['status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    $log = Activity::query()
        ->where('subject_type', $this->exam->getMorphClass())
        ->where('subject_id', $this->exam->getKey())
        ->pluck('description')
        ->all();

    expect(Exam::query()->withoutWorkspaceScope()->whereKey($this->exam->getKey())->value('status'))->toBe('published')
        ->and($log)->toBe(['published']);
});

it('offers only the exam\'s own workspace\'s courses, and refuses another\'s', function (): void {
    examPanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    Livewire::test(EditExam::class, ['record' => $this->exam->getRouteKey()])
        ->fillForm(['course_id' => $this->homeCourse->getKey()])
        ->call('save')
        ->assertHasFormErrors(['course_id']);

    expect(Exam::query()->withoutWorkspaceScope()->whereKey($this->exam->getKey())->value('course_id'))
        ->toBe($this->otherCourse->getKey());
});
