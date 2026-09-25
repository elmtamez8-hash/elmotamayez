<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Models\User;
use App\Modules\Courses\Exceptions\CourseDeletionRefused;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| ⛔ A COURSE SOMEBODY BOUGHT IS NEVER DELETED, AND ONE NOBODY BOUGHT IS DELETED SOFTLY.
|
| `enrollments.course_id` and `orders.course_id` carry no foreign key, and
| `Course` had no `SoftDeletes` although `courses.deleted_at` has existed since
| the table did — so deleting a course from the API or the panel removed the row
| outright, every enrolment kept pointing at it, and `EnrollmentResource` read
| `->uuid` on null: ONE deleted course and «تعلّمي» answered 500 for every buyer.
|
| The refusal lives in `Course::booted()` (the one place all three doors — the
| API's destroy, the panel's delete and its bulk delete — reach). How it bites:
| empty the `deleting` hook ⇒ the three refusal cases delete the course; remove
| `SoftDeletes` ⇒ the free case is not soft-deleted; remove `withTrashed()` from
| `Enrollment::course()` ⇒ the buyer's list drops the course; remove
| `whereHas('course')` from the index ⇒ the orphan case answers 500.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
        'title' => 'BOUGHT-COURSE',
    ]);
});

function courseDeletionEnrol(Course $course, User $student): Enrollment
{
    return Enrollment::create([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'source' => 'manual',
        // Historical on purpose: a finished enrolment is still somebody's record.
        'status' => 'expired',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);
}

it('refuses the API delete of a course with an enrolment, in Arabic, and keeps the row', function (): void {
    courseDeletionEnrol($this->course, User::factory()->create());

    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/courses/{$this->course->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكن حذف هذا الكورس لأنّ طلاباً سُجِّلوا فيه، وحذفُه يُفقدُهم ما اشتروه.');

    expect(DB::table('courses')->where('id', $this->course->getKey())->value('deleted_at'))->toBeNull();
});

it('refuses the API delete of a course with only an order against it', function (): void {
    DB::table('orders')->insert([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => 'course',
        'amount_minor' => 1000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/courses/{$this->course->uuid}")->assertStatus(422);

    expect(DB::table('courses')->where('id', $this->course->getKey())->value('deleted_at'))->toBeNull();
});

it('soft-deletes a course nobody bought', function (): void {
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/courses/{$this->course->uuid}")->assertNoContent();

    $this->assertSoftDeleted('courses', ['id' => $this->course->getKey()]);
});

it('counts the enrolments of ANOTHER workspace than the one the deleter stands in', function (): void {
    // A platform reader stands in whatever workspace their own
    // `last_workspace_id` names. A scoped count would see zero rows about this
    // course and wave the deletion through.
    courseDeletionEnrol($this->course, User::factory()->create());

    [$elsewhere, $elsewhereOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($elsewhere, $elsewhereOwner);

    $course = Course::query()->withoutWorkspaceScope()->findOrFail($this->course->getKey());

    expect(fn () => $course->delete())->toThrow(CourseDeletionRefused::class);
    expect(DB::table('courses')->where('id', $this->course->getKey())->value('deleted_at'))->toBeNull();
});

it('refuses the panel delete with a notification, not an error page', function (): void {
    courseDeletionEnrol($this->course, User::factory()->create());

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(EditCourse::class, ['record' => $this->course->getRouteKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(DB::table('courses')->where('id', $this->course->getKey())->value('deleted_at'))->toBeNull();
});

it('refuses a bulk delete that holds one bought course, and deletes nothing', function (): void {
    courseDeletionEnrol($this->course, User::factory()->create());

    $free = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListCourses::class)
        ->callTableBulkAction('delete', [$this->course, $free])
        ->assertNotified();

    expect(DB::table('courses')->whereIn('id', [$this->course->getKey(), $free->getKey()])->whereNotNull('deleted_at')->count())
        ->toBe(0);
});

dataset('buyer shapes', [
    'null context' => [false],
    'stamped elsewhere' => [true],
]);

function courseDeletionActAsBuyer(User $student, bool $stamped): void
{
    if ($stamped) {
        [$elsewhere] = test()->createWorkspaceWithOwner();
        $student->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    }

    Sanctum::actingAs($student);
    app()->forgetInstance(WorkspaceContext::class);
}

it('keeps a soft-deleted course on its buyer\'s list', function (bool $stamped): void {
    $student = User::factory()->create();
    courseDeletionEnrol($this->course, $student);

    // Stamped by hand: the guard refuses to delete a course with an enrolment,
    // so this is the state of a row that predates the guard.
    DB::table('courses')->where('id', $this->course->getKey())->update(['deleted_at' => now()]);

    courseDeletionActAsBuyer($student, $stamped);

    $this->getJson('/api/v1/enrollments')
        ->assertOk()
        ->assertJsonPath('data.0.course_uuid', $this->course->uuid)
        ->assertJsonPath('data.0.course_title', 'BOUGHT-COURSE');
})->with('buyer shapes');

it('leaves out an enrolment whose course row is gone, instead of a 500 for the whole list', function (bool $stamped): void {
    $student = User::factory()->create();
    courseDeletionEnrol($this->course, $student);

    // The shape a hard delete left on a live database before this change.
    $gone = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
    courseDeletionEnrol($gone, $student);
    DB::table('courses')->where('id', $gone->getKey())->delete();

    courseDeletionActAsBuyer($student, $stamped);

    $this->getJson('/api/v1/enrollments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.course_uuid', $this->course->uuid);
})->with('buyer shapes');
