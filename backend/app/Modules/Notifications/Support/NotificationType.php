<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Shared\Support\GuardianPermission;

/**
 * Every kind of notification the platform can send.
 *
 * An enum rather than a table (research R3): the type is a code reference — a
 * listener asks for it by name, a preference maps onto it, and PHPStan checks it
 * at analysis time. What editors need to change is the *wording*, and that lives
 * in message_templates.
 *
 * The five guardian-facing types have no producer yet; theirs arrive with specs
 * 005, 006 and 008. Defining them now is what makes those phases add a listener
 * instead of an architecture.
 */
enum NotificationType: string
{
    case EnrollmentCreated = 'enrollment_created';
    case CertificateIssued = 'certificate_issued';
    case CertificateRegenerated = 'certificate_regenerated';
    case TeacherApplicationApproved = 'teacher_application_approved';
    case TeacherApplicationRejected = 'teacher_application_rejected';
    case TeacherApplicationChangesRequested = 'teacher_application_changes_requested';
    case SecurityAlert = 'security_alert';
    case AttendanceAlert = 'attendance_alert';
    case PaymentReminder = 'payment_reminder';
    case AppointmentReminder = 'appointment_reminder';
    case ExamResult = 'exam_result';
    case AcademicWarning = 'academic_warning';
    case SessionReport = 'session_report';
    case SessionCancelled = 'session_cancelled';
    case SessionRecordingFailed = 'session_recording_failed';
    case SettlementRateApproved = 'settlement_rate_approved';
    case SettlementRateRejected = 'settlement_rate_rejected';
    case SettlementPeriodClosed = 'settlement_period_closed';
    case TeacherPayoutIssued = 'teacher_payout_issued';

    /*
    | Credits (006). Four types rather than one with a "level" variable: a
    | preference switches a TYPE off, so folding them together would make
    | silencing the gentle first nudge also silence the notice that access has
    | been withheld — and the mandatory flag below could then only be all or
    | nothing.
    */
    case CreditBalanceLow = 'credit_balance_low';
    case CreditBalanceCritical = 'credit_balance_critical';
    case AccessWithheld = 'access_withheld';
    case AccessRestored = 'access_restored';

    /*
    | The credits nobody came back for (Q-8). Its own type rather than a second
    | use of CreditBalanceLow, which says the opposite thing — and optional,
    | because it is a courtesy about money the student already holds and which
    | never expires.
    */
    case CreditBalanceDormant = 'credit_balance_dormant';

    /*
    | Spec 008. The import runs in a queued job, so the teacher has closed the
    | tab long before it finishes — this notification IS how they learn it is
    | done, and the only route to the row-by-row report.
    |
    | Optional and never reaching a guardian: it is a teacher's own housekeeping,
    | and it says nothing about any student.
    */
    case QuestionImportReady = 'question_import_ready';

    /*
    | ⚠️ AND ITS OWN TYPE FOR THE FAILURE, on the precedent of PaymentConfirmed
    | vs PaymentFailed below. A file that was not a CSV at all produces no rows,
    | so the "done" message would read «اكتمل الاستيراد… أُضيف 0» — which is a
    | success sentence describing a failure. The teacher closed the tab after the
    | 202; without this they learn nothing at all.
    */
    case QuestionImportFailed = 'question_import_failed';

    /*
    | ⚠️ AND ITS OWN TYPE FOR "HANDED IN BUT NOT FINISHED", separate from
    | ExamResult. An attempt waiting on an essay carries the machine-marked total
    | and nothing else, so the student who answered every essay perfectly reads
    | the mark for the multiple choice alone and concludes they failed. Sending
    | ExamResult there would be a true sentence about a number that is not their
    | result — and it reaches guardians, who would then be told the same.
    |
    | Optional and student-only: nothing about it changes what the account can do.
    */
    case ExamPendingGrading = 'exam_pending_grading';

    public function label(): string
    {
        return match ($this) {
            self::EnrollmentCreated => 'تسجيل في كورس',
            self::CertificateIssued => 'إصدار شهادة',
            self::CertificateRegenerated => 'إعادة إصدار شهادة',
            self::TeacherApplicationApproved => 'اعتماد طلب التدريس',
            self::TeacherApplicationRejected => 'رفض طلب التدريس',
            self::TeacherApplicationChangesRequested => 'طلب تعديلات على الطلب',
            self::SecurityAlert => 'تنبيه أمني',
            self::AttendanceAlert => 'تنبيه حضور',
            self::PaymentReminder => 'تذكير دفع',
            self::PaymentConfirmed => 'تأكيد دفع',
            self::PaymentFailed => 'فشل دفع',
            self::ReceiptApproved => 'اعتماد إيصال',
            self::ReceiptRejected => 'رفض إيصال',
            self::PaymentReversed => 'إعادة دفعة',
            self::AppointmentReminder => 'تذكير موعد',
            self::ExamResult => 'نتيجة اختبار',
            self::AcademicWarning => 'إنذار أكاديمي',
            self::SessionReport => 'تقرير ما بعد الحصة',
            self::SessionCancelled => 'إلغاء حصة',
            self::SessionRecordingFailed => 'تعذّر نشر تسجيل الحصة',
            self::SettlementRateApproved => 'اعتماد سعر التسوية',
            self::SettlementRateRejected => 'رفض طلب سعر التسوية',
            self::SettlementPeriodClosed => 'إغلاق فترة التسوية',
            self::TeacherPayoutIssued => 'تنفيذ صرف',
            self::CreditBalanceLow => 'اقتراب نفاد الرصيد',
            self::CreditBalanceCritical => 'الرصيد على وشك النفاد',
            self::CreditBalanceDormant => 'رصيد غير مستخدَم',
            self::AccessWithheld => 'إيقاف الوصول لعدم كفاية الرصيد',
            self::AccessRestored => 'استئناف الوصول',
            self::QuestionImportReady => 'تقرير استيراد الأسئلة',
            self::QuestionImportFailed => 'تعذّر استيراد الأسئلة',
            self::ExamPendingGrading => 'ورقتك بانتظار التصحيح',
        };
    }

    /**
     * Channels used when the user has saved no preference for this type (FR-028).
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        // In-app for everything, because it is the only implemented channel. When
        // a second one lands, the types that should reach further get it here —
        // and every user who never touched their settings follows along.
        return [NotificationChannel::InApp];
    }

    /*
    | The payment path (007). Five types, because each answers a different
    | question the payer is actually asking: did my money arrive, why did it
    | not, was my receipt accepted, was it refused, and was a payment I already
    | made taken back.
    |
    | All five are mandatory: every one of them changes what the account can do,
    | and a preference that hid a failed payment would leave someone blocked
    | with no way to learn why.
    */
    case PaymentConfirmed = 'payment_confirmed';
    case PaymentFailed = 'payment_failed';
    case ReceiptApproved = 'receipt_approved';
    case ReceiptRejected = 'receipt_rejected';
    case PaymentReversed = 'payment_reversed';

    /**
     * A mandatory type cannot be switched off by the user (FR-029) and is never
     * deferred or digested (FR-035). Reserved for security and hard financial
     * consequence — a locked account or a suspended enrollment is not something
     * a preference should be able to hide.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::SecurityAlert, self::PaymentReminder => true,
            // FR-035 — hard financial consequence. Being withheld, and being let
            // back in, are facts about what the account can DO right now; a
            // preference that hid them would leave someone locked out with no
            // way to learn why, and then unlocked without knowing they may
            // return. The two gentler tiers stay optional, because a nudge is a
            // nudge.
            self::AccessWithheld, self::AccessRestored => true,
            // 007 — money that arrived, money that did not, and money taken
            // back. Each one changes what the account can do next.
            self::PaymentConfirmed,
            self::PaymentFailed,
            self::ReceiptApproved,
            self::ReceiptRejected,
            self::PaymentReversed => true,
            default => false,
        };
    }

    /**
     * Whether authorised guardians receive this alongside the student (FR-021).
     * These are the five the addendum names.
     */
    public function targetsGuardians(): bool
    {
        return match ($this) {
            self::AttendanceAlert,
            self::PaymentReminder,
            self::AppointmentReminder,
            self::ExamResult,
            self::AcademicWarning,
            self::SessionReport,
            self::SessionCancelled,
            // The second tier and the block reach the guardian; the first does
            // not. FR-030's ladder is the whole point — a quiet word to the
            // student first, and only then the person who pays.
            self::CreditBalanceCritical,
            self::AccessWithheld,
            self::AccessRestored,
            // Money the student is holding and has forgotten. The guardian who
            // paid it is precisely who would want to know.
            self::CreditBalanceDormant,
            // 007 — the guardian is usually the payer, so these are addressed to
            // them as much as to the student.
            self::PaymentConfirmed,
            self::PaymentFailed,
            self::ReceiptApproved,
            self::ReceiptRejected,
            self::PaymentReversed => true,
            default => false,
        };
    }

    /**
     * The guardian permission that gates this type. Null means no guardian ever
     * receives it, which is the same set as targetsGuardians() returning false.
     */
    public function requiredGuardianPermission(): ?GuardianPermission
    {
        return match ($this) {
            self::AttendanceAlert => GuardianPermission::Attendance,
            self::PaymentReminder => GuardianPermission::Payments,
            self::AppointmentReminder => GuardianPermission::Schedule,
            self::ExamResult => GuardianPermission::Results,
            self::AcademicWarning => GuardianPermission::AcademicWarnings,
            // The post-session report is attendance news before it is
            // anything else, so it rides the guardian's attendance consent.
            self::SessionReport => GuardianPermission::Attendance,
            self::SessionCancelled => GuardianPermission::Schedule,
            // Payments, specifically. A guardian with no right to see the
            // financial record has no business being told about a payment due
            // on it — the permission is the message's audience, not a filter
            // applied afterwards.
            self::CreditBalanceCritical,
            self::AccessWithheld,
            self::AccessRestored,
            self::CreditBalanceDormant,
            self::PaymentConfirmed,
            self::PaymentFailed,
            self::ReceiptApproved,
            self::ReceiptRejected,
            self::PaymentReversed => GuardianPermission::Payments,
            default => null,
        };
    }

    public function queue(): string
    {
        return $this->isMandatory()
            ? (string) config('notifications.queues.mandatory')
            : (string) config('notifications.queues.default');
    }
}
