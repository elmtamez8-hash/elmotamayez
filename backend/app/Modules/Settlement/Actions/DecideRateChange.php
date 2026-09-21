<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Events\SettlementRateApproved;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Support\Money;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Approval is the ONLY thing in the system that writes a settlement rate.
 *
 * Not a convention anyone has to remember — the only writer. That is what makes
 * "no rate takes effect without approval" (SC-005أ) a property of the code
 * rather than a rule a reviewer has to keep noticing, and it is why there is no
 * `SettlementRate::create()` anywhere else in the module.
 *
 * The new row is INSERTED with its own effective date. The old row is left
 * exactly as it was, so every hour already taught keeps the price it was taught
 * at (FR-011) — an UPDATE here would reprice the past with one keystroke.
 */
class DecideRateChange extends Action
{
    use LogsActivity;

    public function __construct(private readonly DispatchNotification $notifications) {}

    public function approve(RateChangeRequest $request, User $by): SettlementRate
    {
        $this->refuseIfDecided($request);

        return DB::transaction(function () use ($request, $by): SettlementRate {
            $rate = SettlementRate::query()->create([
                'workspace_id' => (int) $request->workspace_id,
                'teacher_profile_id' => (int) $request->teacher_profile_id,
                'session_type' => $request->session_type,
                'subject_id' => $request->subject_id,
                'grade_level' => $request->grade_level,
                'amount_minor' => $request->requested_amount_minor,
                'currency' => (string) $request->currency,
                // From now, never backdated. FR-011 has no exception, and an
                // "effective from" the admin could type is the exception.
                'effective_from' => now(),
                'approved_by' => $by->getKey(),
                'rate_change_request_id' => (int) $request->getKey(),
            ]);

            $request->forceFill([
                'status' => RateRequestStatus::Approved,
                'decided_by' => $by->getKey(),
                'decided_at' => now(),
            ])->save();

            // Consumed by the cost-plus pricing in 006, which does not exist
            // yet. Declared with its deferred consumer in contracts/events.md
            // rather than left for a review to find, which is how this phase
            // inherited SessionDelivered and how 005 lost SessionCancelled.
            $this->logActivity('settlement.rate.approved', $rate, [
                'amount_minor' => $rate->amount_minor,
                'session_type' => $rate->session_type->value,
            ]);

            SettlementRateApproved::dispatch($rate);

            return $rate;
        });
    }

    public function reject(RateChangeRequest $request, User $by, string $reason): RateChangeRequest
    {
        $this->refuseIfDecided($request);

        if (trim($reason) === '') {
            // FR-013أ — "no" with no reason is a request the teacher will simply
            // file again next week, and a rule nobody can learn.
            throw new DomainException('اذكر سبب الرفض.');
        }

        $request->forceFill([
            'status' => RateRequestStatus::Rejected,
            'decided_by' => $by->getKey(),
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        // Subject is the REQUEST, not a rate: a rejection creates no rate, and
        // pointing the entry at one that does not exist is how an audit trail
        // acquires a row nobody can open.
        $this->logActivity('settlement.rate.rejected', $request, [
            'requested_amount_minor' => $request->requested_amount_minor,
            'reason' => $reason,
        ]);

        $this->tellTeacher($request);

        return $request;
    }

    /**
     * يُبلَّغُ المدرّسُ برفضِ طلبِه — والسببُ هو الرسالة.
     *
     * ⛔ `SettlementRateRejected` كانَ **حالةً مُعدَّدةً بقُرّاءٍ بلا كاتب**: صفٌّ
     * في `NotificationCategory`، وقالبٌ مبذورٌ وحيٌّ على الإنتاجِ منذُ
     * ٢٠٢٦-٠٨-٢٨ — ولا سطرَ في الشجرةِ كلِّها يُرسِلُه. عائلةُ
     * `ClassSessionStatus::Interrupted`: متطلَّبٌ ظنَّ الجميعُ أنّه مُنفَّذٌ لأنّ
     * كلَّ ما حولَه مكتوب. فالمدرّسُ كانَ يُرفَضُ طلبُه ولا يعلم.
     *
     * ⚠️ **من الفعلِ لا من حدث، وسابقتُه `DecidePlanChange::tellTeacher()`** —
     * وهو أقربُ نظيرٍ في المنتَج: قرارُ منصّةٍ في طلبِ مدرّس، يُبلِّغُ من داخلِ
     * الفعلِ في الحالتَين. والاعتمادُ يمرُّ بحدثٍ لأنّ لذلك الحدثِ مستهلِكاً
     * آخرَ (تسعيرُ ٠٠٦)؛ والرفضُ ليس له مستهلِكٌ غيرُ جرسِ المدرّس، وحدثٌ
     * بمستمِعٍ واحدٍ في الوحدةِ نفسِها زخرفةٌ لا فصل.
     *
     * ⚠️ واسمُه `tellTeacher` لا `notify`: `ProviderAgnosticTest` يُسقِطُ البناءَ
     * على دالّةٍ بهذا الاسمِ داخلَ `Actions/` — وهو محقٌّ، إذ لا يُميِّزُ مساعِداً
     * خاصّاً من `Notifiable::notify()`.
     *
     * ⚠️ وخارجَ أيِّ معاملة: الصفُّ محفوظٌ قبلَ هذا السطر، فإخفاقُ إشعارٍ لا
     * يُلغي قراراً اتُّخِذ.
     */
    private function tellTeacher(RateChangeRequest $request): void
    {
        $teacher = User::query()->find($request->teacherProfile?->user_id);

        if ($teacher === null) {
            return;
        }

        $this->notifications->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SettlementRateRejected,
            variables: [
                'amount' => Money::format(
                    (int) $request->requested_amount_minor,
                    (string) $request->currency,
                ),
                'reason' => (string) $request->decision_reason,
            ],
            actionUrl: '/manage/settlement',
            subject: $teacher,
            workspaceId: (int) $request->workspace_id,
        ));
    }

    private function refuseIfDecided(RateChangeRequest $request): void
    {
        if ($request->status !== RateRequestStatus::Pending) {
            throw new DomainException('هذا الطلب مقرَّر سلفاً.');
        }
    }
}
