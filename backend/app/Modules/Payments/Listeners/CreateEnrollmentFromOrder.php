<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MoveMember;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Events\Contracts\CarriesPaidOrder;
use App\Modules\Payments\Models\Order;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Support\WorkspaceContext;
use DomainException;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * When a manual payment is approved, auto-enroll the student in the order's course.
 *
 * Reuses the EnrollStudent action so the same EnrollmentCreated event pipeline fires
 * (notifications, activity log, etc.).
 *
 * ShouldHandleEventsAfterCommit because ApproveOrder fires PaymentApproved from
 * INSIDE its own DB::transaction. Without it this job is pushed to the queue
 * while that transaction is still open, a worker picks it up within
 * milliseconds, and it reads the order as `pending` — or does not find it at
 * all. The student has paid and got nothing, with no retry, because the job
 * "succeeded". Same reason as CompleteExamLessonOnSubmission.
 */
class CreateEnrollmentFromOrder implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly EnrollStudent $enrollStudent,
        private readonly MoveMember $move,
        private readonly CohortDirectory $cohorts,
        private readonly WorkspaceContext $workspace,
    ) {}

    /**
     * ⚠️ Typed on the CONTRACT, not on PaymentApproved — the manual approval and
     * the gateway capture both enrol, and a class type here threw a TypeError on
     * the first successful gateway payment. This listener is the constitution's
     * eighth critical path; its whole route runs before this phase is committed.
     */
    public function handle(CarriesPaidOrder $event): void
    {
        $order = $event->order();

        // A credit order carries a course too — that is the whole point of Q-7 —
        // so `course_id === null` no longer separates the two. Without this
        // branch, buying credits would hand the student the entire course free.
        if ($order->kind !== OrderKind::Course) {
            return;
        }

        if ($order->course_id === null) {
            return;
        }

        $course = $order->course;

        if ($course === null) {
            return;
        }

        // Scoped to this job only — set() would leak the workspace into the next
        // job handled by the same worker process.
        $this->workspace->forWorkspace($order->workspace, function () use ($order, $course): void {
            $this->enrollStudent->handle(
                course: $course,
                student: $order->user,
                source: 'purchase',
                orderId: $order->getKey(),
            );

            $this->assignChosenCohort($order);
        });
    }

    /**
     * الإسنادُ الذي اختارَه الموظَّفُ لحظةَ الاعتماد (٠٣٤ · FR-016أ).
     *
     * ⛔ **هنا، بعدَ التسجيلِ مباشرةً — ولا يمكنُ أن يكونَ داخلَ الاعتماد.** قِيسَ:
     * كلا كاتبَي العضويّةِ يرفضُ طالباً بلا تسجيلٍ قائم، والتسجيلُ يكتبُه هذا
     * المستمِعُ **المُصطفُّ بعدَ تثبيتِ معاملةِ المال** — فكتابةُ العضويّةِ داخلَ
     * `ApproveOrder` **مستحيلةٌ لا صعبة**: لا تسجيلَ بعد.
     *
     * ⚠️ **ونداءٌ مباشرٌ لا مستمِعٌ ثانٍ على حدثِ إنشاءِ التسجيل، خلافاً لنصِّ
     * `T023` — والسببانِ مكتوبانِ:**
     *   ١) `EnrollStudent` هو `firstOrCreate`، و`EnrollmentCreated` **لا يقعُ على
     *      صفٍّ قائم**. والطالبُ الذي اشترى كورساً هو فيه سلفاً (لا شيءَ في بابِ
     *      الطلبِ يمنعُه) كانَ سيُسقِطُ اختيارَ الموظَّفِ بصمتٍ ويترُكُ المقعدَ
     *      مُطالَباً به بلا عضويّة.
     *   ٢) قصدُ `T023` هو ألّا تتسابقَ كتابةُ العضويّةِ مع كاتبِ التسجيل؛ والنداءُ
     *      المتتالي **أقوى** من الترتيبِ بحدثٍ: لا مهمّةَ ثانيةَ أصلاً.
     * والاتّجاهُ مسموحٌ به كما هو اليوم: `Payments` ينادي فِعلاً في `Learning`
     * (`EnrollStudent` فوقَه بسطر)، والممنوعُ هو العكس.
     *
     * ⚠️ **وإعادةُ النداءِ بلا أثر**: المستمِعُ مُصطفٌّ ويُعادُ عندَ الفشل،
     * و`CohortMembershipWriter` يرفضُ «هو في هذه المجموعةِ سلفاً» — فتُلتقَطُ
     * الرفضةُ وتُقرَأُ نجاحاً، وإلّا لدارَ الطلبُ في `failed_jobs` إلى الأبد.
     */
    private function assignChosenCohort(Order $order): void
    {
        $uuid = $order->metadata['assigned_cohort_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '' || $order->course_id === null) {
            return;
        }

        $cohortId = $this->cohorts->resolveCohortId($uuid, (int) $order->course_id);

        if ($cohortId === null) {
            Log::warning('034: an approved order names a group that no longer resolves', [
                'order_id' => $order->getKey(),
            ]);

            return;
        }

        $cohort = Cohort::query()->withoutWorkspaceScope()->find($cohortId);

        if ($cohort === null) {
            return;
        }

        try {
            $this->move->handle(
                $cohort,
                $order->user,
                // المعتمِدُ هو الفاعل: السجلُّ يقولُ مَن أسنَد، لا «انضمَّ الطالب»
                // عن عضويّةٍ أنشأَها اعتمادُ الإدارة (SC-002).
                $order->approver ?? $order->user,
                dropNote: 'أسندتك إدارة المنصّة إلى مجموعة عند اعتماد طلبك، فأُغلق طلب انتقالك السابق.',
                // ⛔ `T021أ` — المقعدُ أُخِذَ في `ApproveOrder` قبلَ قبضِ المال.
                seatAlreadyClaimed: true,
            );
        } catch (DomainException $e) {
            /*
            | «هو في هذه المجموعةِ سلفاً» جوابُ إعادةِ المحاولة، لا عُطل. وأيُّ
            | رفضٍ آخرَ يُسجَّلُ ولا يُرمى: الطلبُ مُعتمَدٌ والمالُ قُبِضَ
            | والتسجيلُ كُتِبَ فوقَ هذا السطرِ — ورميةٌ هنا تُعيدُ المهمّةَ إلى
            | الأبدِ عن شيءٍ لن يتغيّر.
            */
            Log::warning('034: the chosen group could not be written', [
                'order_id' => $order->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
