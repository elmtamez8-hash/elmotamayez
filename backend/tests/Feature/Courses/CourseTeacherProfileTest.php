<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Actions\CreateCourse;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Events\TeacherApplicationSubmitted;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⛔ `courses.teacher_profile_id` — العمودُ الذي لم يكتبْه أحدٌ منذُ ٠٠٦.
 *
 * تعليقُه على النموذجِ يقولُ إنّه «الذي بدونِه لا يعملُ بحثُ السعرِ المعتمَدِ
 * إطلاقاً»، ولا سطرَ في الشجرةِ كلِّها كان يُسنِدُه. قِيسَ على الإنتاج
 * 2026-09-04: سبعةُ كورسات، العمودُ فارغٌ في سبعتِها — فمحرّكُ الفوترةِ لم
 * يُسعِّرْ كورساً حقيقيّاً واحداً قطّ، وتقرأُ الشاشتانِ «لا تسعير متاح لهذا
 * الكورس حاليّاً»: جملةٌ تبدو سياسةً لا عطلاً، ولا سطرَ خطأٍ في أيِّ سجلّ.
 */
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(
        [],
        ['platform_role' => PlatformRole::Teacher],
    );

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
});

function courseHere(?User $creator = null): Course
{
    return app(CreateCourse::class)->handle(
        new CreateCourseDTO(
            title: 'دورةٌ في الجبر',
            // مطلوبةٌ في الإجراءِ نفسِه لا في الطلب: الفهرسُ مرجعيّةٌ يبذرُها
            // `TaxonomySeeder` قبلَ كلِّ اختبارِ Feature.
            subjectUuid: (string) Subject::query()->value('uuid'),
        ),
        $creator ?? test()->teacher,
    );
}

it('stamps the author own profile when they already have one', function (): void {
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    expect(courseHere()->fresh()?->teacher_profile_id)->toBe($profile->getKey());
});

it('falls back to the owner of the workspace for a course an assistant creates', function (): void {
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $assistant = $this->addWorkspaceMember($this->workspace, 'assistant-teacher');

    /*
    | ⚠️ سعرُ الكورسِ سعرُ **صاحبِ المساحة** لا سعرُ من ضغطَ الزرّ. ولا ملفَّ
    | للمساعدِ أصلاً، فبلا الارتدادِ يُولَدُ كورسُ المساعدِ بلا سعرٍ إلى الأبد.
    */
    expect(courseHere($assistant)->fresh()?->teacher_profile_id)->toBe($profile->getKey());
});

it('writes null quietly when no profile exists yet, and never throws', function (): void {
    /*
    | ⚠️ الفرعُ الشائعُ منذُ ٠٢٥ لا النادر: المساحةُ تُولَدُ مع التسجيلِ وتحملُ
    | `courses.create` من يومِها، ولا ملفَّ قبلَ إرسالِ الطلب. الرميُ هنا يمنعُ
    | كلَّ مدرّسٍ جديدٍ من التأليفِ حتّى يتقدّم.
    */
    expect(courseHere()->fresh()?->teacher_profile_id)->toBeNull();
});

it('claims the courses that came before the profile, at the moment it is born', function (): void {
    $before = courseHere();

    expect($before->fresh()?->teacher_profile_id)->toBeNull();

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $application = TeacherApplication::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'current_step' => 4,
    ]);

    event(new TeacherApplicationSubmitted($application));

    /*
    | ⚠️ هذا هو التوكيدُ الذي يُسقِطُ «إصلاحَ `CreateCourse` وحدَه». بدونِ المستمِعِ
    | يبقى الكورسُ المؤلَّفُ قبلَ التقدّمِ بلا سعرٍ إلى الأبدِ، وهو الترتيبُ
    | الطبيعيُّ لا الاستثناء.
    */
    expect($before->fresh()?->teacher_profile_id)->toBe($profile->getKey());
});

it('leaves another teacher course alone when a colleague applies', function (): void {
    /*
    | ⚠️ الاتّجاهُ الذي يُفسِدُ التسعير. صفٌّ يحملُ ملفّاً آخرَ هو كورسُ مدرّسٍ
    | آخرَ في الأكاديميّةِ نفسِها؛ مطالبةٌ به تنقلُ سعرَ كورسِ زميلِه إلى سعرِ هذا
    | المتقدّم — تسعيرٌ يتغيّرُ بلا قرارٍ من أحد.
    */
    $colleague = $this->addWorkspaceMember($this->workspace, 'teacher');

    $colleagueProfile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $colleague->getKey(),
    ]);

    $theirs = courseHere($colleague);

    expect($theirs->fresh()?->teacher_profile_id)->toBe($colleagueProfile->getKey());

    $mine = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $application = TeacherApplication::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'teacher_profile_id' => $mine->getKey(),
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'current_step' => 4,
    ]);

    event(new TeacherApplicationSubmitted($application));

    expect($theirs->fresh()?->teacher_profile_id)->toBe($colleagueProfile->getKey());
});

it('backfills the rows that were born before any of this, and skips what it cannot resolve', function (): void {
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    // شكلُ الإنتاج: صفوفٌ كُتِبَت قبلَ وجودِ الكاتب. `DB::table` لا النموذج، لأنّ
    // `CreateCourse` صارَ يملأُ العمودَ فلا يُنتِجُ الحالةَ المرادَ ردمُها.
    $resolvable = DB::table('courses')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'كورسٌ قديم',
        'slug' => 'old-course',
        'created_by' => $this->teacher->getKey(),
        'teacher_profile_id' => null,
        'status' => 'draft',
        'visibility' => 'private',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$otherWorkspace, $stranger] = $this->createWorkspaceWithOwner();

    $orphan = DB::table('courses')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'كورسٌ بلا مدرّسٍ معتمَد',
        'slug' => 'orphan-course',
        'created_by' => $stranger->getKey(),
        'teacher_profile_id' => null,
        'status' => 'draft',
        'visibility' => 'private',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (require base_path(
        'app/Modules/Courses/Database/Migrations/2026_09_04_000100_backfill_course_teacher_profiles.php'
    ))->up();

    expect(DB::table('courses')->where('id', $resolvable)->value('teacher_profile_id'))->toBe($profile->getKey())
        // ⚠️ الفراغُ حالةٌ مشروعةٌ يجيبُ عنها المنتَجُ بصدق: مساحةٌ بلا ملفِّ
        // مدرّسٍ لا سعرَ فيها فعلاً. والرميُ يوقفُ النشرةَ على صفوفِ عرض.
        ->and(DB::table('courses')->where('id', $orphan)->value('teacher_profile_id'))->toBeNull();
});
