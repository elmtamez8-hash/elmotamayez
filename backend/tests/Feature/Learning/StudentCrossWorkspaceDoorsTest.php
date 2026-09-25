<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **أبوابُ الطالبِ حينَ يكونُ `users.last_workspace_id` مساحةً أخرى.**
|
| قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٤ من بابِ الدَّور: ٤٠٤ عن كورسٍ على الشاشة. والسببُ
| عائلةٌ من أربعِ طبقاتٍ لا طبقةٌ واحدة — الربطُ الضمنيُّ، ثمّ
| `belongsToCurrentWorkspace()` فوقَ فرعِ المِلكيّةِ في `EnrollmentPolicy`، ثمّ
| فوقَ فرعِ «منشور» في `CoursePolicy`، ثمّ الاستعلاماتُ التي تجدُ صفوفَ الطالبِ
| بنفسِها.
|
| ⚠️ **والعمودُ مختومٌ لكلِّ طالبٍ أُضيفَ يوماً إلى مساحةِ عمل** —
| `addWorkspaceMember` · `AcceptInvitation` · البذور — وستّةٌ منهم على الإنتاج.
|
| ⚠️ **ثلاثةُ أشياءَ في هذه التركيبةِ تجعلُها تقيسُ شيئاً**، وبدونِ أيٍّ منها
| تمرُّ خضراءَ فوقَ البناءِ المكسور:
|   ١) `forceFill` للختم — العمودُ في `$guarded` فالإسنادُ الجَماعيُّ يُسقِطُه بصمت.
|   ٢) الختمُ على مساحةٍ **غيرِ** مساحةِ الكورس.
|   ٣) `forgetInstance` لا `forget()` — الثانيةُ تُثبِّتُ السياقَ على العدمِ ولا
|      تُعيدُه إلى «لم يُحَلَّ بعد»، فتقيسُ إنساناً لا يُنتِجُه الإنتاج.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّة الكورس']);
    [$this->elsewhere] = $this->createWorkspaceWithOwner(['name' => 'أكاديميّةٌ أخرى']);

    $ctx = app(WorkspaceContext::class);

    $this->course = $ctx->forWorkspace($this->home, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $this->home->getKey(),
        'created_by' => $this->teacher->getKey(),
        'price_minor' => 10_000,
    ]));

    $this->freeCourse = $ctx->forWorkspace($this->home, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $this->home->getKey(),
        'created_by' => $this->teacher->getKey(),
        'price_minor' => 0,
        // «كورس مجاني» — the only thing that makes a course free (2026-09-25).
        'is_free_enrollment' => true,
    ]));

    $ctx->forWorkspace($this->home, fn (): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->home->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]));

    // طالبٌ مختومٌ على مساحةٍ ليست مساحةَ الكورس — وهو السطرُ الذي يُشغِّلُ العطب.
    $this->student = $this->addWorkspaceMember($this->elsewhere, 'student');
    $this->student->forceFill(['last_workspace_id' => $this->elsewhere->getKey()])->save();

    $this->enrollment = $ctx->forWorkspace($this->home, fn (): Enrollment => Enrollment::factory()->create([
        'workspace_id' => $this->home->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => 'active',
    ]));
});

/** يفتحُ الباب كما تفتحُه الصفحة. */
function asStampedStudent(): void
{
    Sanctum::actingAs(test()->student);
    app()->forgetInstance(WorkspaceContext::class);
}

it('opens the curriculum of a course at another teacher', function (): void {
    asStampedStudent();

    $this->getJson('/api/v1/courses/'.$this->course->uuid.'/curriculum')->assertOk();
});

it('lists the student\'s own enrolments across every teacher', function (): void {
    asStampedStudent();

    $this->getJson('/api/v1/enrollments')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('reads the announcements of a course at another teacher', function (): void {
    asStampedStudent();

    $this->getJson('/api/v1/courses/'.$this->course->uuid.'/announcements')->assertOk();
});

it('lists the groups of a course at another teacher', function (): void {
    asStampedStudent();

    $this->getJson('/api/v1/courses/'.$this->course->uuid.'/cohorts')->assertOk();
});

/*
| ⛔ «lets the student buy from a second teacher» walked `POST /courses/{course}/orders`,
| the one-off course purchase REMOVED on 2026-09-25 (owner decision: a course is
| sold through a plan only). The cross-teacher money door is now the
| subscription, which `PurchaseSubscription` resolves without the scope.
*/

it('lets the student take a free course from a second teacher', function (): void {
    asStampedStudent();

    $this->postJson('/api/v1/courses/'.$this->freeCourse->uuid.'/enroll')->assertCreated();
});
