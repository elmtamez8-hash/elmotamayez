<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| A course's status from `/admin` goes through the Action, not the column.
|
| ⚠️ Filament's default save was `$record->update($data)`: a course published,
| unpublished or archived from the panel left nothing in the activity log, while
| the API's `/publish` writes one through `PublishCourse`. The log is the only
| place that answers who took a course off the marketplace, and when.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$workspace, $teacher] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $this->panelCourse = fn (string $status): Course => app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): Course => Course::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->getKey(),
            'status' => $status,
        ]),
    );

    /*
    | An officer with NO workspace of their own. `CourseResource::getEloquentQuery()`
    | does not drop the workspace scope the way `EnrollmentResource` does, so an
    | officer stamped into another workspace cannot open a foreign course here
    | at all — a separate defect, outside what this file measures.
    */
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
});

function coursePanelLog(Course $course): array
{
    return Activity::query()
        ->where('subject_type', $course->getMorphClass())
        ->where('subject_id', $course->getKey())
        ->pluck('description')
        ->all();
}

it('logs a publish made from the panel, as the API does', function (): void {
    $course = ($this->panelCourse)('draft');

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Course::query()->withoutWorkspaceScope()->whereKey($course->getKey())->value('status'))->toBe('published')
        ->and(coursePanelLog($course))->toBe(['published']);
});

it('logs taking a course down, which had no Action at all', function (string $to, string $logged): void {
    $course = ($this->panelCourse)('published');

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['status' => $to])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Course::query()->withoutWorkspaceScope()->whereKey($course->getKey())->value('status'))->toBe($to)
        ->and(coursePanelLog($course))->toBe([$logged]);
})->with([
    'unpublish' => ['draft', 'unpublished'],
    'archive' => ['archived', 'archived'],
]);

it('writes nothing to the log when the status did not move', function (): void {
    $course = ($this->panelCourse)('published');

    Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
        ->fillForm(['title' => 'عنوانٌ جديد'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Course::query()->withoutWorkspaceScope()->whereKey($course->getKey())->value('title'))->toBe('عنوانٌ جديد')
        ->and(coursePanelLog($course))->toBe([]);
});
