<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 040 · US2 — the teacher marks one lesson per course as its «حصة تجريبية».
| `PUT /courses/{course}/trial-lesson`, `CoursePolicy::chooseTrialLesson`,
| `SetCourseTrialLesson`.
*/

/** Sign in as the workspace's owner — a teacher who decides the course's price. */
function actAsTrialOwner(Course $course): User
{
    /** @var User $owner */
    $owner = $course->workspace()->firstOrFail()->owner()->firstOrFail();
    test()->setCurrentWorkspace($course->workspace()->firstOrFail(), $owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($course->workspace_id);
    Sanctum::actingAs($owner);

    return $owner;
}

function markTrial(Course $course, ?string $lesson, ?string $replacing = null): TestResponse
{
    return test()->putJson("/api/v1/courses/{$course->uuid}/trial-lesson", array_filter([
        'lesson' => $lesson,
        'replacing' => $replacing,
    ], fn ($value) => $value !== null) + ['lesson' => $lesson]);
}

it('marks a lesson, and moving the mark replaces the first', function (): void {
    [$course, $first, $workspace] = trialFixture();
    actAsTrialOwner($course);

    $second = $first->replicate(['uuid']);
    $second->uuid = (string) Str::uuid();
    $second->order = 2;
    $second->saveQuietly();

    markTrial($course, (string) $first->uuid)->assertOk()
        ->assertJsonPath('data.trial_lesson.uuid', (string) $first->uuid)
        ->assertJsonPath('data.trial_status', 'visible');

    markTrial($course, (string) $second->uuid)->assertOk()
        ->assertJsonPath('data.trial_lesson.uuid', (string) $second->uuid);

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBe($second->id);
});

it('refuses an ineligible lesson with its reason', function (): void {
    [$course, $lesson] = trialFixture(['is_high_value' => true]);
    actAsTrialOwner($course);

    markTrial($course, (string) $lesson->uuid)->assertUnprocessable()
        ->assertJsonPath('errors.lesson.0', 'الدروس المعلَّمة عالية القيمة لا تكون حصة تجريبية.');
});

it('refuses a lesson of another course in the same workspace', function (): void {
    [$course, , $workspace] = trialFixture();
    actAsTrialOwner($course);

    $other = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $course->created_by,
    ]);
    $foreign = Lesson::query()->withoutWorkspaceScope()->where('course_id', $course->id)->firstOrFail()->replicate(['uuid']);
    $foreign->uuid = (string) Str::uuid();
    $foreign->course_id = $other->id;
    $foreign->order = 9;
    $foreign->saveQuietly();

    markTrial($course, (string) $foreign->uuid)->assertUnprocessable()
        ->assertJsonPath('errors.lesson.0', 'اختر درساً من هذا الكورس.');
});

it('refuses a lesson from another workspace as if it did not exist', function (): void {
    [$course] = trialFixture();
    [, $foreign] = trialFixture();
    actAsTrialOwner($course);

    markTrial($course, (string) $foreign->uuid)->assertUnprocessable()
        ->assertJsonValidationErrors('lesson');

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBeNull();
});

it('refuses an assistant by default: the trial is the teacher\'s call', function (): void {
    [$course, $lesson, $workspace] = trialFixture();
    actAsTrialOwner($course);

    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($workspace, $assistant);
    Sanctum::actingAs($assistant);

    markTrial($course, (string) $lesson->uuid)->assertForbidden()
        ->assertJsonPath('message', 'اختيار الحصة التجريبية لمدرّس الكورس أو لمن فوّضه.');
});

it('lets an assistant the teacher authorised choose it', function (): void {
    [$course, $lesson, $workspace] = trialFixture();
    actAsTrialOwner($course);

    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($workspace, $assistant);
    $assistant->givePermissionTo(Permissions::COURSES_TRIAL_CHOOSE);
    Sanctum::actingAs($assistant);

    markTrial($course, (string) $lesson->uuid)->assertOk();

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBe($lesson->id);
});

it('clears only the trial the reader saw, so a stale tab cannot wipe a newer pick', function (): void {
    [$course, $first] = trialFixture();
    actAsTrialOwner($course);

    $second = $first->replicate(['uuid']);
    $second->uuid = (string) Str::uuid();
    $second->order = 2;
    $second->saveQuietly();

    markTrial($course, (string) $first->uuid)->assertOk();
    markTrial($course, (string) $second->uuid)->assertOk();

    // The stale tab still believes the first lesson is the trial.
    markTrial($course, null, (string) $first->uuid)->assertOk()
        ->assertJsonPath('data.trial_lesson.uuid', (string) $second->uuid);

    markTrial($course, null, (string) $second->uuid)->assertOk()
        ->assertJsonPath('data.trial_lesson', null);
});

it('drops the mark when the lesson is deleted', function (): void {
    [$course, $lesson] = trialFixture();
    actAsTrialOwner($course);
    markTrial($course, (string) $lesson->uuid)->assertOk();

    Lesson::query()->withoutWorkspaceScope()->whereKey($lesson->id)->delete();

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBeNull();
});

it('tells the teacher why a pick is not shown yet', function (array $lesson, ?array $asset, string $status): void {
    [$course, $model] = trialFixture($lesson, $asset);
    actAsTrialOwner($course);

    markTrial($course, (string) $model->uuid)->assertOk()->assertJsonPath('data.trial_status', $status);
})->with([
    'a draft lesson' => [['status' => 'draft'], null, 'unpublished'],
    'an upload still processing' => [['type' => 'video', 'external_url' => null], ['status' => 'processing', 'ready_at' => null], 'processing'],
]);

it('carries the switch\'s answers on the author\'s course and lesson reads', function (): void {
    [$course, $lesson] = trialFixture();
    actAsTrialOwner($course);
    markTrial($course, (string) $lesson->uuid)->assertOk();

    $this->getJson("/api/v1/courses/{$course->uuid}")->assertOk()
        ->assertJsonPath('can_choose_trial', true)
        ->assertJsonPath('trial_lesson_uuid', (string) $lesson->uuid)
        ->assertJsonPath('trial_status', 'visible');

    $this->getJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")->assertOk()
        ->assertJsonPath('is_trial', true)
        ->assertJsonPath('trial_refusal', null)
        ->assertJsonPath('can_choose_trial', true)
        ->assertJsonPath('trial_status', 'visible');
});

/*
| The /admin course screen (owner request 2026-10-09): a super admin picks the
| trial there, through the same Action and rule as the API.
*/
it('lets a super admin choose the trial from the panel, through the same rule', function (): void {
    [$course, $lesson, $workspace] = trialFixture();
    panelReadyTrialCourse($course, $workspace);

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['trial_lesson_uuid' => (string) $lesson->uuid])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBe($lesson->id);

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['trial_lesson_uuid' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBeNull();
});

it('shows the rule\'s refusal on the panel field', function (): void {
    [$course, $lesson, $workspace] = trialFixture(['is_high_value' => true]);
    panelReadyTrialCourse($course, $workspace);

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['trial_lesson_uuid' => (string) $lesson->uuid])
        ->call('save')
        ->assertHasFormErrors(['trial_lesson_uuid']);

    expect(Course::query()->withoutWorkspaceScope()->find($course->id)->trial_lesson_id)->toBeNull();
});

/** The fixture's teacher as a teaching member (the panel's «أنشأه» list), read by a super admin. */
function panelReadyTrialCourse(Course $course, Workspace $workspace): void
{
    $workspace->members()->syncWithoutDetaching([$course->created_by => ['role' => Roles::TEACHER]]);
    test()->actingAs(User::factory()->create(['is_super_admin' => true]));
}
