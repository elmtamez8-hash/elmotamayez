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

    public function view(User $user, Enrollment $enrollment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($enrollment))->denied()) {
            return $workspaceCheck;
        }

        if ($enrollment->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        return $user->can(Permissions::ENROLLMENTS_VIEW_ALL)
            ? Response::allow()
            : Response::deny('You are not authorized to view this enrollment.');
    }

    public function create(User $user): Response
    {
        return Response::allow();
    }

    public function completeLessons(User $user, Enrollment $enrollment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($enrollment))->denied()) {
            return $workspaceCheck;
        }

        if ($enrollment->student_user_id !== $user->getKey()) {
            return Response::deny('You can only complete lessons for your own enrollment.');
        }

        if (! $enrollment->isActive()) {
            return Response::deny('This enrollment is not active.');
        }

        return Response::allow();
    }

    /**
     * التراجعُ عن التقدّم: درسٌ واحدٌ أو الكورسُ كلُّه.
     *
     * ⚠️ **قدرةٌ مستقلّةٌ عن `completeLessons`، ولو أعيدَ استعمالُها لَرُدَّ
     * ٤٠٣ على الشخصِ الوحيدِ الذي تعنيه الميزة.** ذلكَ الشرطُ يطلبُ
     * `isActive()`، وحالةُ من أنهى الكورسَ هي `completed` لا `active` — فالطالبُ
     * الذي أنهى ويريدُ الإعادةَ من أوّلِها كانَ سيُمنَع. والسؤالُ مختلفٌ فعلاً لا
     * شكلاً: الإتمامُ كسبُ تقدّمٍ ويليقُ به تسجيلٌ نشط، والتراجعُ إنقاصُ تقدّمِ
     * صاحبِه ويليقُ به كلُّ من بدأَ الطريقَ أو أنهاه.
     *
     * ⚠️ **والموقوفُ والملغى يبقيانِ ممنوعَين**: الشرطُ يُوسَّعُ حالةً واحدةً لا
     * يُرفَع، وتسجيلٌ أُوقِفَ لا يُعدَّلُ تقدّمُه من جانبِ الطالب.
     */
    public function resetProgress(User $user, Enrollment $enrollment): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($enrollment))->denied()) {
            return $workspaceCheck;
        }

        if ($enrollment->student_user_id !== $user->getKey()) {
            return Response::deny('You can only reset your own enrollment.');
        }

        if (! $enrollment->isActive() && ! $enrollment->isCompleted()) {
            return Response::deny('This enrollment is not active.');
        }

        return Response::allow();
    }
}
