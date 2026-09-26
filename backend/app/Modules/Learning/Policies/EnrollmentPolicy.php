<?php

declare(strict_types=1);

namespace App\Modules\Learning\Policies;

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class EnrollmentPolicy extends BasePolicy
{
    /*
    | ⚠️ قراءةُ **قائمةِ** التسجيلات، وغيابُها كان بابَ لوحةٍ مفتوحاً على مصراعَيه.
    |
    | `Filament\Resources\Resource::canViewAny()` تُفوِّضُ إلى سياسةِ النموذج —
    | فإن لم تكنْ فيها دالّةٌ بهذا الاسمِ سقطَ الطلبُ إلى `Response::allow()` في
    | `get_authorization_response()`، أي **سمحَ للجميع**. و`view()` أدناه لا تُستدعى
    | لصفوفِ جدولٍ أبداً: قائمةُ Filament تسألُ عن الفعلِ الجمعيِّ وحدَه. فكانت شاشةُ
    | التسجيلاتِ تعرضُ اسمَ كلِّ طالبٍ وبريدَه وتقدُّمَه لكلِّ من يفتحُ اللوحة.
    |
    | وليس فرعُ المِلكيّةِ هنا: قائمةٌ ليست صفّاً، والطالبُ يقرأُ تسجيلَه من
    | `/api/v1/enrollments` بفلترةٍ صريحةٍ على مُعرِّفِه لا من هذا الفعل.
    */
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::ENROLLMENTS_VIEW_ALL)
            ? Response::allow()
            : Response::deny('You are not authorized to list enrollments.');
    }

    /*
    | ⛔ **فرعُ المِلكيّةِ فوقَ فحصِ المساحة، وترتيبُهما هو المتطلَّب.**
    |
    | `belongsToCurrentWorkspace()` لا يعترضُ حينَ يكونُ السياقُ عدماً — وهذا ما
    | صُلِّحَ في ٢٠٢٦-٠٨-٢٦ — لكنّه **يرفضُ حينَ يُحَلُّ السياقُ إلى مساحةٍ أخرى**،
    | و`users.last_workspace_id` مختومٌ لكلِّ طالبٍ أُضيفَ يوماً إلى مساحةِ عمل.
    | فصاحبُ الصفِّ كانَ يُمنَعُ من صفِّه هو، والفرعُ الذي يسمحُ له مكتوبٌ في
    | السطرِ التالي مباشرةً ولا يُبلَغ.
    |
    | والمِلكيّةُ دعوى أقوى من المساحة: «هذا تسجيلي» لا يحتاجُ إذنَ مساحةٍ
    | ليصحّ. أمّا فرعُ `ENROLLMENTS_VIEW_ALL` أدناه فيحتاجُه ويبقى خلفَه —
    | مدرّسٌ يقرأُ تسجيلَ مساحةٍ أخرى مرفوضٌ كما كان.
    */
    public function view(User $user, Enrollment $enrollment): Response
    {
        if ($enrollment->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($enrollment))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ENROLLMENTS_VIEW_ALL)
            ? Response::allow()
            : Response::deny('You are not authorized to view this enrollment.');
    }

    public function create(User $user): Response
    {
        return Response::allow();
    }

    /*
    | ⛔ **ولا فحصَ مساحةٍ هنا إطلاقاً، وحذفُه هو الإصلاح.**
    |
    | لا يعبرُ السطرَ التاليَ إلّا صاحبُ الصفِّ نفسُه، فالفحصُ فوقَه لم يكنْ يمنعُ
    | إلّا **المالكَ** حينَ يُحَلُّ سياقُه إلى مساحةٍ غيرِ مساحةِ تسجيلِه. حارسٌ
    | لا يردُّ إلّا من يحرسُه ليسَ حارساً.
    */
    public function completeLessons(User $user, Enrollment $enrollment): Response
    {
        if ($enrollment->student_user_id !== $user->getKey()) {
            return Response::deny('You can only complete lessons for your own enrollment.');
        }

        /*
        | ⛔ `grantsContentAccess()`, never `isActive()` (owner decision 2026-09-23).
        | Teachers publish a course lesson by lesson, so a student at 100% sees the
        | next lesson open — and `isActive()` answered «not active» to the one
        | button that marks it done, leaving them below 100% for ever. Completing
        | a lesson asks the same question as opening it.
        */
        if (! $enrollment->grantsContentAccess()) {
            return Response::deny('This enrollment is not active.');
        }

        return Response::allow();
    }

    /**
     * التراجعُ عن التقدّم: درسٌ واحدٌ أو الكورسُ كلُّه.
     *
     * ⚠️ Kept as its own ability even though, since 2026-09-23, it asks the same
     * question as `completeLessons` (`grantsContentAccess()`: `active` or
     * `completed`). Earning progress and giving it back are different decisions,
     * and the day one of them narrows the other must not move with it.
     *
     * ⚠️ **والموقوفُ والملغى يبقيانِ ممنوعَين**: الشرطُ يُوسَّعُ حالةً واحدةً لا
     * يُرفَع، وتسجيلٌ أُوقِفَ لا يُعدَّلُ تقدّمُه من جانبِ الطالب.
     */
    /*
    | ⛔ **ولا فحصَ مساحةٍ هنا إطلاقاً، وحذفُه هو الإصلاح.**
    |
    | لا يعبرُ السطرَ التاليَ إلّا صاحبُ الصفِّ نفسُه، فالفحصُ فوقَه لم يكنْ يمنعُ
    | إلّا **المالكَ** حينَ يُحَلُّ سياقُه إلى مساحةٍ غيرِ مساحةِ تسجيلِه. حارسٌ
    | لا يردُّ إلّا من يحرسُه ليسَ حارساً.
    */
    public function resetProgress(User $user, Enrollment $enrollment): Response
    {
        if ($enrollment->student_user_id !== $user->getKey()) {
            return Response::deny('You can only reset your own enrollment.');
        }

        if (! $enrollment->grantsContentAccess()) {
            return Response::deny('This enrollment is not active.');
        }

        return Response::allow();
    }
}
