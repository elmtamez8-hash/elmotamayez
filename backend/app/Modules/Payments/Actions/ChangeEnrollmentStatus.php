<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Shared\Actions\Action;
use App\Shared\Events\CourseAccessEnded;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A platform correction to an enrolment's status — the one door `/admin`'s
 * enrolment screen writes through.
 *
 * ⚠️ IT LIVED IN THE PAGE. `EditEnrollment` carried the refusals and the close
 * itself (a conditional UPDATE and `CourseAccessEnded`), so the rule «closing a
 * paid enrolment releases its seats» existed only behind one Filament form.
 * Business logic belongs to an Action that any door can share.
 *
 * ⚠️ AND IT LIVES IN `Payments`, NOT `Learning`, ON PURPOSE. A subscription
 * enrolment is closed through {@see SubscriptionAccess::closeEnrollment()}, and
 * `ContextIsolationTest` fails the build over any `App\Modules\Payments` named
 * under `Modules/Learning/` — money drives the lower layers, never the reverse.
 * Payments already writes enrolments (`CreateEnrollmentFromOrder`), so this is
 * the sanctioned direction.
 *
 * The refusals, each of which used to be the page's alone:
 *  - `cancelled` is never chosen here. Cancelling goes through reversing the
 *    order (`ReverseCourseOrder`), which fires `CourseAccessWithdrawn`; writing
 *    the column alone leaves seats held and a group membership behind.
 *  - an already-cancelled row takes no new status: reopening it is a new
 *    purchase (`EnrollStudent::handOver()`), not an edit.
 *  - a subscription enrolment that no longer grants is not reopened by hand:
 *    access bought for a period would be handed back with nothing to close it.
 */
class ChangeEnrollmentStatus extends Action
{
    use LogsActivity;

    /**
     * @throws AuthorizationException when the actor is not the super admin
     * @throws DomainException when the transition is one of the refusals above
     */
    public function handle(User $actor, Enrollment $enrollment, EnrollmentStatus $to): Enrollment
    {
        /*
        | ⚠️ THE SUPER ADMIN, NOT A PERMISSION. `enrollments.*` are tenant
        | permissions, and a platform officer who owns a workspace holds them
        | there — this Action edits ANY workspace's row, so a tenant permission
        | would let that officer rewrite another teacher's student.
        */
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('تعديلُ التسجيلِ من اللوحةِ لمديرِ المنصّةِ وحدَه.');
        }

        $from = $enrollment->status;

        if ($from === EnrollmentStatus::Cancelled->value) {
            throw new DomainException('أُلغِيَ هذا التسجيلُ بعكسِ دفعته، ولا يُعادُ فتحُه من هنا.');
        }

        if ($to === EnrollmentStatus::Cancelled) {
            throw new DomainException('الإلغاءُ يتمُّ من «عكس الدفعة» في الطلب، لا من هنا.');
        }

        if ($from === $to->value) {
            return $enrollment;
        }

        $granting = in_array($to->value, Enrollment::GRANTING_STATUSES, true);

        if ($granting && self::isLapsedSubscription($enrollment)) {
            throw new DomainException('وصولُ الاشتراكِ يعودُ بتجديده، لا بتعديلِ الحالةِ من هنا.');
        }

        $closesAccess = $to === EnrollmentStatus::Expired
            && in_array($from, Enrollment::GRANTING_STATUSES, true);

        DB::transaction(function () use ($enrollment, $to, $closesAccess): void {
            if (! $closesAccess) {
                $enrollment->update(['status' => $to->value]);

                return;
            }

            /*
            | ⚠️ «منتهٍ» على تسجيلِ اشتراكٍ يمرُّ بـ`SubscriptionAccess::closeEnrollment()`
            | لا بكتابةٍ خامّ: الكتابةُ الخامُ لا تُطلِقُ `SubscriptionEnded`، فيبقى
            | الطالبُ محجوزاً في حصصِ كورسٍ لم يعدْ يفتحُه ويُخصَمُ منه عندَ كلِّ
            | تسليم. والتسجيلُ المشترى له العطبُ نفسُه، فيُغلَقُ بتحديثٍ مشروطٍ
            | ويُطلَقُ `CourseAccessEnded` — لا `CourseAccessWithdrawn`، فذاك يُخرجُ
            | الطالبَ من مجموعته بسببِ «استرداد» لم يحدث.
            */
            if ($enrollment->source === 'subscription') {
                SubscriptionAccess::closeEnrollment($enrollment);

                return;
            }

            self::closeGrantedEnrollment($enrollment);
        });

        $this->logActivity('enrollment.status_changed', $enrollment, [
            'from' => $from,
            'to' => $to->value,
        ]);

        return $enrollment->refresh();
    }

    /**
     * A subscription enrolment that no longer grants — read by the screen too,
     * so the options it offers and the refusal here are one predicate.
     */
    public static function isLapsedSubscription(?Enrollment $enrollment): bool
    {
        return $enrollment !== null
            && $enrollment->source === 'subscription'
            && ! in_array($enrollment->status, Enrollment::GRANTING_STATUSES, true);
    }

    /**
     * The non-subscription half: a conditional UPDATE (so a second save, or a
     * sweep in between, announces nothing twice), then say that access ended.
     */
    private static function closeGrantedEnrollment(Enrollment $enrollment): void
    {
        $closed = Enrollment::query()
            ->withoutWorkspaceScope()
            ->whereKey($enrollment->getKey())
            ->whereIn('status', Enrollment::GRANTING_STATUSES)
            ->update(['status' => EnrollmentStatus::Expired->value]);

        if ($closed === 0) {
            return;
        }

        CourseAccessEnded::dispatch(
            (int) $enrollment->workspace_id,
            (int) $enrollment->student_user_id,
            [(int) $enrollment->course_id],
        );
    }
}
