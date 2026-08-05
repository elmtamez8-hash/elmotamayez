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
            self::AppointmentReminder => 'تذكير موعد',
            self::ExamResult => 'نتيجة اختبار',
            self::AcademicWarning => 'إنذار أكاديمي',
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
            self::AcademicWarning => true,
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
