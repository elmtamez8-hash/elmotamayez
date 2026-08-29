<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use Tests\Feature\Notifications\NotificationCategoryTest;

/**
 * The handful of subjects a person's feed is actually about.
 *
 * ⚠️ FORTY-EIGHT TYPES IS NOT A FILTER, IT IS A SECOND LIST TO READ. The types
 * exist for producers and for the preferences screen, where naming each one is
 * the point; a reader looking at sixty-four unread rows wants «which of these is
 * about my money and which about my lessons», and the answer is six or seven
 * words, not forty-eight.
 *
 * ⚠️ THE MAP RUNS CATEGORY → TYPES, IN ONE DIRECTION ONLY, AND THAT IS THE WHOLE
 * SAFETY OF IT. Written the other way — `match ($type)` returning a category — a
 * type added tomorrow and forgotten here throws `UnhandledMatchError` the moment
 * somebody receives one, and the notification centre 500s for exactly the person
 * it was sent to. This way an unclassified type is simply absent from the tabs
 * and still present in «الكل»: the reader keeps their message, and
 * {@see NotificationCategoryTest} fails the build
 * so it does not stay unclassified.
 *
 * ⚠️ AND THE STUDENT'S MONEY IS NOT THE TEACHER'S PAY. `Balance` and
 * `Settlement` are separate categories rather than one «financial» tab, the same
 * line spec 006 draws between the two contexts: what a student owes and what a
 * teacher is owed share no key, no query and no screen anywhere else in this
 * product, and merging them here would be one tab meaning two things depending
 * on who is reading it.
 */
enum NotificationCategory: string
{
    case Study = 'study';

    case Sessions = 'sessions';

    case Achievements = 'achievements';

    case Messages = 'messages';

    case Balance = 'balance';

    case Settlement = 'settlement';

    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Study => 'الدراسة',
            self::Sessions => 'الحصص والمواعيد',
            self::Achievements => 'الشهادات والإنجازات',
            self::Messages => 'الرسائل والإعلانات',
            self::Balance => 'الرصيد والمدفوعات',
            self::Settlement => 'الأجر والتسوية',
            self::Account => 'الحساب والخصوصيّة',
        };
    }

    /**
     * The types this subject covers.
     *
     * @return list<NotificationType>
     */
    public function types(): array
    {
        return match ($this) {
            self::Study => [
                NotificationType::EnrollmentCreated,
                NotificationType::ExamResult,
                NotificationType::ExamPendingGrading,
                NotificationType::AcademicWarning,
                NotificationType::AssignmentSubmitted,
                NotificationType::AssignmentGraded,
                NotificationType::PeriodicReviewPublished,
                NotificationType::QuestionImportReady,
                NotificationType::QuestionImportFailed,
            ],
            self::Sessions => [
                NotificationType::AppointmentReminder,
                NotificationType::AttendanceAlert,
                NotificationType::SessionReport,
                NotificationType::SessionCancelled,
                NotificationType::SessionRecordingFailed,
                NotificationType::SessionRecordingUnavailable,
                // 021. A group IS a timetable — the picker shows nothing but
                // session times — so a transfer belongs beside the sessions it
                // moves, not under «الدراسة».
                NotificationType::CohortTransferRequested,
                NotificationType::CohortTransferApproved,
                NotificationType::CohortTransferRejected,
            ],
            self::Achievements => [
                NotificationType::CertificateIssued,
                NotificationType::CertificateRegenerated,
                NotificationType::LevelUp,
                NotificationType::BadgeAwarded,
                NotificationType::RewardRedeemed,
            ],
            self::Messages => [
                NotificationType::Announcement,
                NotificationType::AnnouncementUrgent,
                NotificationType::ChatMessage,
                NotificationType::TeacherOffboardingNotice,
            ],
            self::Balance => [
                NotificationType::PaymentReminder,
                NotificationType::PaymentConfirmed,
                NotificationType::PaymentFailed,
                NotificationType::PaymentReversed,
                NotificationType::ReceiptApproved,
                NotificationType::ReceiptRejected,
                NotificationType::CreditBalanceLow,
                NotificationType::CreditBalanceCritical,
                NotificationType::CreditBalanceDormant,
                NotificationType::AccessWithheld,
                NotificationType::AccessRestored,
                /*
                 * Spec 011 · the store. Filed under the student's MONEY rather
                 * than under «achievements» or a tab of its own: both are facts
                 * about a purchase — where the parcel got to, and money that is
                 * owed back — and the person scanning this tab is the person
                 * asking what they paid for and what happened to it.
                 *
                 * ⚠️ AND NEVER UNDER `Settlement`. That tab is the TEACHER's pay,
                 * and spec 006 draws the line between the two through the whole
                 * product; one tab meaning two things depending on who opened it
                 * is what `it keeps what a student owes apart from what a teacher
                 * is owed` refuses.
                 */
                NotificationType::ShipmentStatusChanged,
                NotificationType::StorePurchaseUnavailable,
            ],
            self::Settlement => [
                NotificationType::SettlementRateApproved,
                NotificationType::SettlementRateRejected,
                NotificationType::SettlementPeriodClosed,
                NotificationType::TeacherPayoutIssued,
            ],
            self::Account => [
                NotificationType::SecurityAlert,
                NotificationType::TeacherApplicationApproved,
                NotificationType::TeacherApplicationRejected,
                NotificationType::TeacherApplicationChangesRequested,
                NotificationType::GuardianConsentRequired,
                NotificationType::GuardianConsentConflict,
                NotificationType::DataOwnershipTransferred,
                NotificationType::DataRequestCreated,
                NotificationType::DataRequestCompleted,
            ],
        };
    }

    /**
     * The same list as the wire values the `type` column holds.
     *
     * @return list<string>
     */
    public function typeValues(): array
    {
        return array_map(static fn (NotificationType $type): string => $type->value, $this->types());
    }

    /**
     * Which category a stored type belongs to, or null when nobody classified it.
     *
     * ⚠️ NULLABLE ON PURPOSE, AND BUILT BY WALKING THE MAP ABOVE RATHER THAN BY A
     * SECOND `match`. Two hand-written directions is two lists that disagree at
     * the first type anybody adds — and the disagreement is silent, because each
     * one is individually well-formed.
     *
     * @return array<string, self> keyed by the type's wire value
     */
    public static function byType(): array
    {
        $map = [];

        foreach (self::cases() as $category) {
            foreach ($category->typeValues() as $value) {
                $map[$value] = $category;
            }
        }

        return $map;
    }
}
