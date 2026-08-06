<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\RateRequestStatus;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Support\RateResolver;
use App\Modules\Settlement\Support\SettlementSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * The teacher asks. Nothing changes yet.
 *
 * Both limits are enforced HERE rather than in the FormRequest, because the
 * FormRequest only guards the API: a Filament screen, a seeder or a console
 * command all reach the Action directly, and a rule that lives in validation
 * alone is a rule the admin panel walks around (Constitution II).
 */
class RequestRateChange extends Action
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly SettlementSettings $settings,
    ) {}

    public function handle(
        TeacherProfile $teacher,
        ClassSessionType $type,
        int $requestedAmountMinor,
        User $by,
        ?int $subjectId = null,
        ?string $gradeLevel = null,
    ): RateChangeRequest {
        if ($requestedAmountMinor <= 0) {
            throw new DomainException('السعر المطلوب يجب أن يكون أكبر من صفر.');
        }

        $this->refuseSecondPending($teacher, $type, $subjectId, $gradeLevel);
        $this->refuseTooSoon($teacher);

        $current = $this->rates->resolve(
            (int) $teacher->getKey(),
            $type,
            now(),
            $subjectId,
            $gradeLevel,
        );

        // Explicit rather than `$current?->currency ?? …`: `??` swallows property
        // access on null all by itself, so the nullsafe there reads as a guard
        // that is not doing anything. A teacher with no rate yet is a real case —
        // their first request — and it deserves to be visible in the code.
        $currency = $current === null
            ? $this->settings->currency()
            : (string) $current->currency;

        return RateChangeRequest::query()->create([
            'workspace_id' => (int) $teacher->workspace_id,
            'teacher_profile_id' => (int) $teacher->getKey(),
            'session_type' => $type,
            'subject_id' => $subjectId,
            'grade_level' => $gradeLevel,
            'current_amount_minor' => $current?->amount_minor,
            'requested_amount_minor' => $requestedAmountMinor,
            'currency' => $currency,
            'status' => RateRequestStatus::Pending,
            'requested_by' => $by->getKey(),
            'requested_at' => now(),
        ]);
    }

    /**
     * Two open requests for one rate is a queue where the second silently
     * overwrites whatever the first was approved at.
     */
    private function refuseSecondPending(
        TeacherProfile $teacher,
        ClassSessionType $type,
        ?int $subjectId,
        ?string $gradeLevel,
    ): void {
        $exists = RateChangeRequest::query()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('session_type', $type->value)
            ->where('subject_id', $subjectId)
            ->where('grade_level', $gradeLevel)
            ->where('status', RateRequestStatus::Pending)
            ->exists();

        if ($exists) {
            throw new DomainException('لديك طلب سعر قيد الاعتماد على هذا النطاق.');
        }
    }

    /**
     * FR-013ب — a price that can be changed at will is not a price.
     *
     * The refusal names the date the next request becomes possible: "no" with no
     * date is a support ticket, and the teacher has no way to find out otherwise.
     */
    private function refuseTooSoon(TeacherProfile $teacher): void
    {
        $windowDays = $this->settings->rateRequestWindowDays();
        $since = now()->subDays($windowDays);

        $recent = RateChangeRequest::query()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('requested_at', '>=', $since)
            ->orderBy('requested_at')
            ->get();

        if ($recent->count() < $this->settings->rateRequestsPerWindow()) {
            return;
        }

        $nextAllowed = $recent->first()?->requested_at?->copy()->addDays($windowDays);

        throw new DomainException(sprintf(
            'بلغت الحد المسموح لطلبات تغيير السعر. الطلب التالي متاح في %s.',
            $nextAllowed?->toDateString() ?? '—',
        ));
    }
}
