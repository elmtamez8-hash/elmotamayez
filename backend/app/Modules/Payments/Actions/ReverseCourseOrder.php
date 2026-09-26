<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Shared\Actions\Action;
use App\Shared\Events\CourseAccessWithdrawn;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * عكسُ دفعةِ طلبِ كورسٍ معتمَد — المالُ يعودُ والوصولُ يُغلَق، معاً.
 *
 * ⛔ `ReversePayment` كانَ له مُنادٍ واحدٌ هو `CancelSubscription`، فطلبُ كورسٍ
 * مدفوعٍ ثمّ مُسترَدٍّ خارجَ المنصّة لم يكنْ له بابٌ إطلاقاً: الطالبُ يستردُّ
 * مالَه ويبقى الكورسُ مفتوحاً له إلى الأبد.
 *
 * ثلاثُ كتاباتٍ في معاملةٍ واحدة، والترتيبُ مقصود:
 *
 *   ١) **الطلبُ يُطالَبُ به أوّلاً** بتحديثٍ شرطيٍّ `approved → cancelled`.
 *      ضغطتانِ على الزرِّ تقرآنِ `approved` كلتاهما، والخاسرةُ تُصيبُ صفرَ صفوفٍ
 *      وتُقالُ لها جملة — لا عكسٌ ثانٍ لمعاملةٍ عُكِسَت. و`cancelled` لا
 *      `approved` باقٍ: `HandleProviderCallback::closesOrder()` يرفضُ طلباً ملغىً،
 *      فإشعارُ بوّابةٍ متأخّرٌ عن الطلبِ نفسِه لا يُعيدُ فتحَ ما أُغلِق.
 *   ٢) **التسجيلُ يُغلَقُ بمفتاحِ `order_id`** لا بـ`SubscriptionAccess::close()`:
 *      ذاك يشترطُ `source = 'subscription'` فيُصيبُ صفرَ صفوفٍ لطلبِ كورس. ومفتاحُ
 *      الطلبِ وحدَه كافٍ ودقيق: `EnrollStudent::handOver()` لا يُسلِّمُ صفّاً قائماً
 *      غيرَ منتهٍ لشراءٍ جديد، فصفٌّ اشتراه الطالبُ سابقاً شراءً مستقلّاً يحملُ
 *      رقمَ طلبِه ذاك ويبقى مفتوحاً — وهو ما يجبُ أن يكون. و`cancelled` لا
 *      `expired`: هذا استردادٌ لا مؤقّت، وكلاهما خارجُ `GRANTING_STATUSES`، فشراءٌ
 *      لاحقٌ يُحيي الصفَّ عبرَ فرعِ `! grantsContentAccess()` في `handOver()`.
 *   ٣) **ثمّ `ReversePayment`**، الذي يُطلِقُ `PaymentReversed` — ومستمِعُه
 *      `ReevaluateOnReversal` هو من يُخبِرُ الطالب. فلا إشعارَ يُرسَلُ من هنا: ثانٍ
 *      من هذا الملفِّ رسالتانِ عن فعلٍ واحد. والمستمِعُ مُصطفٌّ بعدَ التثبيت،
 *      فرفضٌ داخلَ المعاملةِ لا يُرسِلُ للطالبِ خبراً عن عكسٍ لم يقع.
 *
 * ⚠️ لطلبِ الكورسِ وحدَه. طلبُ الأرصدةِ وباقةُ الحصصِ لهما بابُهما
 * (`ReverseCreditOrder`، يسحبُ ما لم يُستهلَكْ من الرصيد)، والاشتراكُ له بابُه
 * (`CancelSubscription`) — وعكسُ أيٍّ منها هنا يُعيدُ المالَ ويتركُ ما اشتُرِيَ
 * به قائماً.
 *
 * ⚠️ والمقاعدُ المحجوزةُ ومكانُ المجموعةِ يُحرَّرانِ بحدثٍ لا من هنا (قرارُ
 * المالك ٢٠٢٦-٠٩-٢٣): `CourseAccessWithdrawn` تسمعُه LiveSessions فتُلغي حجوزَ ما
 * لم يبدأْ، وLearning فتُخرِجُه من المجموعة. والوحدتانِ لا تُنادَيانِ مباشرةً.
 */
class ReverseCourseOrder extends Action
{
    use LogsActivity;

    public function __construct(private readonly ReversePayment $reverse) {}

    public function handle(Order $order, User $by, string $reason): Order
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('اكتبْ سببَ العكس.');
        }

        if ($order->kind !== OrderKind::Course) {
            throw new DomainException('العكسُ من هنا لطلبِ الكورسِ وحدَه.');
        }

        DB::transaction(function () use ($order, $by, $reason): void {
            // ⚠️ بلا نطاقِ ورشة: موظّفُ المنصّةِ ترتدُّ ورشتُه إلى
            // `last_workspace_id`، فتحديثٌ منطوقٌ يُصيبُ صفرَ صفوفٍ على طلبِ ورشةٍ
            // أخرى ويُقرَأُ «عُكِسَ سلفاً» (الطبقةُ الثالثةُ من عيبِ ٠٢٤).
            $claimed = Order::query()
                ->withoutWorkspaceScope()
                ->whereKey($order->getKey())
                ->where('status', OrderStatus::Approved->value)
                ->update(['status' => OrderStatus::Cancelled->value]);

            if ($claimed === 0) {
                throw new DomainException('هذا الطلبُ غيرُ معتمَدٍ الآن — عُكِسَ سلفاً أو لم يُعتمَد.');
            }

            $captured = PaymentTransaction::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->where('status', PaymentStatus::Captured->value)
                ->first();

            if ($captured === null) {
                // يُلغي المعاملةَ كلَّها، ومعها المطالبةَ بالطلبِ أعلاه.
                throw new DomainException('لا دفعةَ محصَّلةً على هذا الطلبِ لتُعكَس.');
            }

            $closing = Enrollment::query()
                ->withoutWorkspaceScope()
                ->where('order_id', $order->getKey())
                ->whereIn('status', Enrollment::GRANTING_STATUSES)
                ->get(['id', 'course_id', 'student_user_id', 'workspace_id']);

            /*
            | ⛔ BUT FIRST, HAND BACK WHAT A STILL-RUNNING SUBSCRIPTION PAYS FOR.
            | `EnrollStudent::handOver()` moves a live subscriber's row to an
            | outright purchase of the same course, so cancelling it here closed
            | the month the student is still paying for. The row goes back to the
            | running subscription on its own clock — as `CancelSubscription` does
            | for a renewal — and is neither closed nor announced as withdrawn.
            */
            $handedBack = SubscriptionAccess::handBackRows(
                $closing,
                (int) $order->getKey(),
                (int) $order->user_id,
            );

            $closing = $closing->reject(
                static fn (Enrollment $row): bool => in_array((int) $row->getKey(), $handedBack, true),
            );

            $closed = Enrollment::query()
                ->withoutWorkspaceScope()
                ->whereIn('id', $closing->pluck('id'))
                ->whereIn('status', Enrollment::GRANTING_STATUSES)
                ->update(['status' => EnrollmentStatus::Cancelled->value]);

            // Read before the close, like `SubscriptionAccess::close()`: by the
            // time a listener runs nothing grants access, so it could not
            // re-derive which courses these were. Dispatched inside the
            // transaction; both listeners are after-commit.
            if ($closing->isNotEmpty()) {
                CourseAccessWithdrawn::dispatch(
                    (int) $order->workspace_id,
                    (int) $order->user_id,
                    array_values(array_unique(array_map(
                        static fn (mixed $id): int => (int) $id,
                        $closing->pluck('course_id')->all(),
                    ))),
                    (int) $by->getKey(),
                );
            }

            $this->reverse->handle($captured, $reason);

            $this->logActivity('order.reversed', $order, [
                'reason' => $reason,
                'reversed_by' => $by->getKey(),
                'transaction_id' => $captured->getKey(),
                'enrollments_closed' => $closed,
                'enrollments_handed_back' => count($handedBack),
            ]);
        });

        return $order->refresh();
    }
}
