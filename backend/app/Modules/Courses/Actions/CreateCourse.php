<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Models\User;
use App\Modules\Courses\DTOs\CreateCourseDTO;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Support\CourseSlug;
use App\Modules\Courses\Support\CourseTeacherProfile;
use App\Modules\Courses\Support\SubjectResolver;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\LogsActivity;
use InvalidArgumentException;

class CreateCourse extends Action
{
    use LogsActivity;

    public function handle(CreateCourseDTO $dto, User $creator): Course
    {
        /*
        | ⚠️ RESOLVED HERE, AND A UUID THAT NAMES NOTHING IS REFUSED. `subjects`
        | is platform reference data with no workspace column, so an `exists` rule
        | would have been correct for once — but the Action is what the seeders and
        | the panel reach with no request behind them, which is where the rule
        | belongs (the repository's standing rule about business rules living in
        | the Action).
        */
        $subjectId = SubjectResolver::id($dto->subjectUuid);

        /*
        | ⛔ **الرفضُ هنا لا في الطلبِ وحدَه، لأنّ الطلبَ بابٌ واحدٌ من ثلاثة.**
        |
        | `courses.course_type` وُلِدَ في ٢٠٢٦-٠٨-٠١ بقيمةٍ افتراضيّةٍ `recorded`
        | و**بلا كاتبٍ في الشجرةِ كلِّها**: لا طلبٌ ولا فعلٌ ولا شاشة. فكلُّ كورسٍ
        | أنشأَه مدرّسٌ بيدِه يقولُ «مسجَّل» عن تصنيفٍ لم يختَرْه أحد — ٦ من ٧ على
        | الإنتاج (قِيسَ ٢٠٢٦-٠٩-١٥)، ومنها كورسٌ يحملُ ثماني حصصٍ حيّةٍ ومجموعةً
        | مفتوحة.
        |
        | و«مطلوب» في `CreateCourseRequest` تحرسُ بابَ الواجهةِ وحدَه: لوحةُ
        | Filament تُنشئُ بـ`handleRecordCreation()` بلا طلبٍ أصلاً، والبذورُ تكتبُ
        | داخلَ `Model::unguarded()`. فالقاعدةُ في الفعلِ — البابُ الوحيدُ الذي
        | يمرُّ منه الثلاثة — كما يوجبُ هذا المستودعُ لكلِّ قاعدةِ عمل.
        */
        if ($dto->courseType === null || ! in_array($dto->courseType, Course::types(), true)) {
            throw new InvalidArgumentException('نوعُ الكورسِ مطلوبٌ وواحدٌ من: '.implode('، ', Course::types()));
        }

        $workspaceId = (int) app(WorkspaceContext::class)->id();

        $course = Course::create([
            'subject_id' => $subjectId,
            'workspace_id' => $workspaceId,
            'title' => $dto->title,
            'slug' => $dto->slug ?? CourseSlug::for($dto->title),
            'description' => $dto->description,
            'price_minor' => $dto->priceMinor,
            'currency' => $dto->currency,
            'status' => $dto->status,
            'visibility' => $dto->visibility,
            'is_sequential' => $dto->isSequential,
            'grade_level' => $dto->gradeLevel,
            'course_type' => $dto->courseType,
            'created_by' => $creator->getKey(),

            /*
            | ⛔ العمودُ الذي لم يكن يكتبُه أحد. تعليقُه على النموذجِ يقولُ منذُ ٠٠٦
            | إنّه «الذي بدونِه لا يعملُ بحثُ السعرِ المعتمَدِ إطلاقاً» — ولا سطرَ
            | في الشجرةِ كلِّها كان يُسنِدُه، فوُلِدَ كلُّ كورسٍ بـ`NULL` وعادت
            | قائمةُ الحزمِ فارغةً لكلِّ طالبٍ ولكلِّ موظّف، تحتَ جملةٍ تبدو
            | سياسةً لا عطلاً. {@see CourseTeacherProfile} تحملُ القاعدةَ وحدَها.
            |
            | و`null` هنا حالةٌ مشروعةٌ لا خطأ: مدرّسٌ لم يُرسِلْ طلبَه بعدُ لا ملفَّ
            | له، ومساحتُه تحملُ `courses.create` منذُ تسجيلِه (٠٢٥). الرميُ هنا
            | يمنعُ كلَّ مدرّسٍ جديدٍ من التأليفِ حتّى يتقدّم — قرارُ منتَجٍ لم
            | يتّخذْه أحد.
            */
            'teacher_profile_id' => CourseTeacherProfile::resolve($workspaceId, (int) $creator->getKey()),
        ]);

        $this->logActivity('created', $course, ['title' => $course->title]);

        return $course;
    }
}
