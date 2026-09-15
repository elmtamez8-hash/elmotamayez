<?php

declare(strict_types=1);

use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Marketplace\Models\Subject;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **`courses.course_type` كانَ عموداً بلا كاتب، وقيمتُه الافتراضيّةُ جوابٌ
| واثقٌ خاطئ.**
|
| وُلِدَ في ٢٠٢٦-٠٨-٠١ بـ`default('recorded')`، ولا طلبٌ ولا فعلٌ ولا شاشةٌ في
| الشجرةِ كلِّها تُسنِدُه. فكلُّ كورسٍ أنشأَه مدرّسٌ بيدِه يقولُ «مسجَّل» عن
| تصنيفٍ لم يختَرْه أحد — **٦ من ٧ على الإنتاج** (قِيسَ ٢٠٢٦-٠٩-١٥)، ومنها كورسٌ
| بثماني حصصٍ حيّةٍ جايّةٍ ومجموعةٍ مفتوحة.
|
| ⚠️ **وهو ليسَ زينة**: صفحةُ الكورسِ عندَ الطالبِ تُسقِطُ تبويبَ «الحصص»
| وعدّادَ الحصّةِ القادمةِ على كلِّ `recorded`، والصفحةُ العامّةُ تطبعُ عليه
| شارةَ «مسجّل». فأربعةُ طلبةٍ مسجَّلينَ في كورسٍ يُدرَّسُ حيّاً لم يكنْ لهم من
| صفحتِه سبيلٌ إلى حصصِهم.
|
| ⚠️ **وهو عائلةُ `subject_id` نفسُها، بفارقٍ يجعلُه أسوأ**: ذاكَ كانَ `NULL`
| ظاهراً، وهذا قيمةٌ تبدو قراراً.
|
| **كيفَ يمسك**: احذفْ `'course_type' => ['required', …]` من
| `CreateCourseRequest` ⇒ يسقطُ شقُّ «يرفضُ كورساً بلا نوع». واحذفِ الرميَ من
| `CreateCourse::handle()` ⇒ يسقطُ شقُّ الفعل، وهو البابُ الذي تمرُّ منه اللوحةُ
| والبذور.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->subject = Subject::factory()->create(['name' => 'الرياضيات']);

    Sanctum::actingAs($this->owner);
});

it('refuses a course with no type', function (): void {
    $this->postJson('/api/v1/courses', [
        'title' => 'بلا نوع',
        'subject' => (string) $this->subject->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('course_type');

    expect(Course::query()->where('title', 'بلا نوع')->exists())->toBeFalse();
});

it('refuses a type that is not one of the three', function (): void {
    $this->postJson('/api/v1/courses', [
        'title' => 'نوعٌ مخترَع',
        'subject' => (string) $this->subject->uuid,
        'course_type' => 'hybrid',
    ])->assertStatus(422)->assertJsonValidationErrors('course_type');
});

/*
| ⚠️ **التوكيدُ على العمودِ لا على الجواب.** الردُّ يُعيدُ ما أُرسِل، وهو بعينِه
| التوكيدُ الذي فاتَ عليه `student_profiles` في ٠١٣: ثلاثةُ أعمدةٍ غيرِ قابلةٍ
| للإسنادِ الجَماعيِّ سقطَت في صمتٍ والردُّ يقولُ إنّها كُتِبَت.
*/
it('stores the type the teacher chose', function (): void {
    $this->postJson('/api/v1/courses', [
        'title' => 'جماعي',
        'subject' => (string) $this->subject->uuid,
        'course_type' => Course::TYPE_GROUP,
    ])->assertCreated()->assertJsonPath('course_type', Course::TYPE_GROUP);

    expect(Course::query()->where('title', 'جماعي')->sole()->course_type)->toBe(Course::TYPE_GROUP);
});

/*
| ⛔ **والفعلُ يرفضُ كذلك، لأنّ الطلبَ بابٌ واحدٌ من ثلاثة.** لوحةُ Filament
| تُنشئُ بـ`handleRecordCreation()` — أي `new Model($data)` — بلا طلبٍ أصلاً،
| والبذورُ تكتبُ داخلَ `Model::unguarded()`. فقاعدةٌ في `FormRequest` وحدَها
| تتركُ البابَينِ الآخرَينِ يكتبانِ القيمةَ الافتراضيّةَ كما كانا.
*/
it('refuses at the Action, where the panel and the seeders walk', function (): void {
    $action = app(CreateCourse::class);

    expect(fn () => $action->handle(
        new CreateCourseDTO(title: 'من اللوحة', subjectUuid: (string) $this->subject->uuid),
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);

    // الضابطُ الموجَب: الرفضُ عن النوعِ وحدَه، لا عن كلِّ نداءٍ للفعل.
    $course = $action->handle(
        new CreateCourseDTO(
            title: 'من اللوحة',
            subjectUuid: (string) $this->subject->uuid,
            courseType: Course::TYPE_INDIVIDUAL,
        ),
        $this->owner,
    );

    expect($course->course_type)->toBe(Course::TYPE_INDIVIDUAL);
});

it('lets a teacher correct the type later, and leaves it alone on any other edit', function (): void {
    $course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_type' => Course::TYPE_RECORDED,
    ]);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['course_type' => Course::TYPE_GROUP])
        ->assertOk()
        ->assertJsonPath('course_type', Course::TYPE_GROUP);

    // ⚠️ `sometimes` لا `required` على التعديل: تعديلُ العنوانِ وحدَه يجبُ ألّا
    // يُرفَضَ لحقلٍ لا يمسُّه — ولا أن يمسحَه.
    $this->putJson("/api/v1/courses/{$course->uuid}", ['title' => 'عنوانٌ جديد'])
        ->assertOk()
        ->assertJsonPath('course_type', Course::TYPE_GROUP);
});

/*
| ⛔ **والهجرةُ تُشتَقُّ من حقيقةٍ لا لبسَ فيها، وتترُكُ ما عداها.**
|
| صفٌّ في `cohorts` لا يُنشِئُه إلّا مدرّسٌ بقصد. أمّا «فردي» فلا دليلَ عليه في
| البيانات — `private_session_minutes` **مدّةٌ لا علامة**، يقرؤُها
| `RequestPrivateSession::durationOf()` بـ`?? 60` — فاشتقاقُه اختراعُ تصنيفٍ لا
| تسجيلُه، وهو نفسُ العطلِ الذي تُصلِحُه الهجرة.
|
| ⚠️ **وتُشغَّلُ مرّتَين**: المرّةُ الواحدةُ خضراءُ أبداً ولا تُثبِتُ شيئاً عن
| الإعادة، وهي القاعدةُ التي كتبَها `RollupIdempotencyTest` في هذا المستودع.
*/
it('derives a group course from the one unambiguous fact, twice', function (): void {
    $grouped = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_type' => Course::TYPE_RECORDED,
    ]);

    Cohort::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $grouped->getKey(),
        'name' => 'مجموعة السبت',
        'created_by' => $this->owner->getKey(),
    ]);

    // لا مجموعةَ لها، ومعها مدّةُ حصّةٍ خاصّة: تبقى كما هي — المدّةُ ليست علامة.
    $bare = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_type' => Course::TYPE_RECORDED,
        'private_session_minutes' => 45,
    ]);

    $migration = require database_path('../app/Modules/Courses/Database/Migrations/2026_09_15_000300_backfill_course_type_for_grouped_courses.php');

    $migration->up();
    $migration->up();

    expect($grouped->fresh()->course_type)->toBe(Course::TYPE_GROUP)
        ->and($bare->fresh()->course_type)->toBe(Course::TYPE_RECORDED);
});
