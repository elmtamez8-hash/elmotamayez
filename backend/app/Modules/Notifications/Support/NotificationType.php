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

    /*
    | The seat holder's half of a failed recording (019 FR-009أ).
    |
    | A separate type from SessionRecordingFailed above, not the same one sent
    | twice, for two reasons. The teacher's message names a page they can act on
    | and a provider reason they can read; a student's names neither and would be
    | a support ticket with extra steps. And a preference switches a TYPE off — one
    | type would mean a student who muted this also muted the teacher's copy of it.
    |
    | ⚠️ AND IT EXISTS BECAUSE `failed` RELEASES THE HELD FEE. Settlement holds a
    | teacher's payment while a recording is neither published nor failed, so
    | marking it failed correctly pays them for the hour they actually taught —
    | and leaves nobody at all still waiting for the recording except the person
    | who booked a seat, whose only recourse was to ask (research §R10).
    */
    case SessionRecordingUnavailable = 'session_recording_unavailable';
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

    /*
    | Homework (US6). Two types, and the recipients differ: the teacher hears
    | that work arrived, the student hears that it was marked. Folding them into
    | one would make a student's preference able to silence a teacher's queue.
    |
    | The graded one reaches guardians — it is a result, on the same footing as
    | ExamResult, and the guardian who asks how their child is doing is asking
    | precisely this. The submitted one does not: it is a teacher's own inbox.
    */
    case AssignmentSubmitted = 'assignment_submitted';
    case AssignmentGraded = 'assignment_graded';

    /*
    | Gamification (009). Both are STUDENT-ONLY and both stay on the bell.
    |
    | ⚠️ AND THAT IS A DECISION, NOT AN OVERSIGHT. Levelling up and earning a badge
    | are the two most frequent good-news events on the platform — several a week
    | for an engaged student. On a guardian's phone that is a daily congratulation
    | they did not ask for, and the predictable result is the guardian muting the
    | number, taking the attendance alert and the payment reminder with it. The
    | quiet channel is the right one for news that is pleasant and not urgent.
    |
    | They are optional for the same reason: nothing about either changes what the
    | account can do.
    */
    case LevelUp = 'level_up';
    case BadgeAwarded = 'badge_awarded';

    /*
    | ⚠️ AND THE THIRD ONE DOES REACH THE GUARDIAN, unlike the two above.
    |
    | A redemption is not a congratulation: the reward may be a DISCOUNT ON A
    | SESSION, which is a change to what the family will be asked to pay. It is
    | also rare — once or twice a term — so it does not carry the "muted within a
    | week" risk that keeps levels and badges on the bell.
    |
    | Gated on the PAYMENTS consent for the same reason the balance messages are:
    | a guardian with no right to see the financial record has no business being
    | told a discount was claimed against it.
    */
    case RewardRedeemed = 'reward_redeemed';

    /*
    | Spec 013 — data protection. Six points, and not one of them existed before
    | this phase: nothing in the product had ever needed to tell a guardian that
    | a child's account was waiting on them, or a student that their own record
    | had just become theirs.
    |
    | ⚠️ A TYPE WITH NO APPROVED TEMPLATE IS DROPPED IN SILENCE. `TemplateRenderer`
    | refuses a missing or unapproved row and `DispatchNotification` logs rather
    | than failing the operation that triggered it — and `tests/Pest.php` seeds the
    | templates before every Feature test, so an assertion about a notification
    | with no template passes by finding nothing. All six get a seeded row in the
    | same commit; `NotificationTemplateCoverageTest` is what keeps it true.
    */
    case GuardianConsentRequired = 'guardian_consent_required';
    case DataOwnershipTransferred = 'data_ownership_transferred';
    case DataRequestCreated = 'data_request_created';
    case DataRequestCompleted = 'data_request_completed';
    case GuardianConsentConflict = 'guardian_consent_conflict';
    case TeacherOffboardingNotice = 'teacher_offboarding_notice';

    /*
    | Spec 030 — a guardian link can finally be settled, so both parties need to
    | hear about it. Two types, not one: «somebody asked» is read by the person who
    | has to decide, and «they answered» by the person who asked.
    |
    | ⚠️ NEITHER TARGETS GUARDIANS, AND THAT IS THE DECISION. Both have a direct
    | recipient — the same shape as `guardian_consent_required`, which is addressed
    | TO a guardian rather than copied to one. Naming either in `targetsGuardians()`
    | would fan a student's own message out to their parent AND move
    | `WhatsAppDefaultsTest`'s exact count (26 as of 027).
    |
    | ⚠️ AND NEITHER GETS A WHATSAPP EXCEPTION. `guardian_consent_required` earned
    | one because a guardian whose only contact with the platform is that message
    | may have no next visit; these two are read by someone who is already here —
    | they were just asked to decide, or they just asked. Every exception is also a
    | template approved at the provider before anything can be delivered.
    |
    | ⚠️ The other direction reuses the EXISTING type rather than inventing a third:
    | `RegisterStudent::inviteGuardian` has been sending `guardian_consent_required`
    | since 013, to a guardian who until now had no button to press. It needed an
    | `actionUrl`, not a new name.
    */
    case GuardianLinkRequested = 'guardian_link_requested';
    case GuardianLinkDecided = 'guardian_link_decided';

    /*
    | Spec 010 — somebody wrote to you and you were not looking (FR-012).
    |
    | ⚠️ IT DOES NOT TARGET GUARDIANS, AND THAT IS A DECISION RATHER THAN AN
    | OMISSION. `defaultChannels()` is derived from `targetsGuardians()`, so
    | naming it there would put every private message between a student and their
    | teacher onto a parent's phone — a different feature, and one that would end
    | the conversation the requirement exists to enable. It also keeps
    | `WhatsAppDefaultsTest`'s exact count where it is.
    |
    | So it reaches the bell alone: the person is signed in somewhere or they are
    | not, and the message itself is waiting for them either way.
    */
    case ChatMessage = 'chat_message';

    /*
    | Spec 010 — the teacher published this student's periodic assessment (FR-029).
    |
    | ⚠️ IT TARGETS GUARDIANS, AND THE LINE IN `requiredGuardianPermission()` IS
    | PART OF THE SAME DECISION. A type named in `targetsGuardians()` with no
    | permission beneath it picks up the WhatsApp channel from `defaultChannels()`,
    | is billed for, and reaches NO guardian at all — `RecipientResolver` merges
    | them only when both are present. It rides `Results`, because an assessment of
    | how a child is doing is the same fact as an exam result in a different shape.
    */
    case PeriodicReviewPublished = 'periodic_review_published';

    /*
    | Spec 010 — a notice from the teacher to a slice of their students (FR-042).
    |
    | ⚠️ NEITHER OF THEM TARGETS GUARDIANS, AND THAT IS THE DECISION THE WHOLE
    | FEATURE TURNS ON. `defaultChannels()` is derived from `targetsGuardians()`,
    | so naming them there would send a paid WhatsApp message to every parent on
    | the platform for every change of a lesson time — several a week, from every
    | teacher their child studies with. The predictable end of that is a muted
    | number, and the attendance alert and the payment reminder go silent with it.
    | An announcement is read where the student already is.
    |
    | ⚠️ AND «URGENT» IS A SECOND TYPE RATHER THAN A FLAG ON THE FIRST. The flag
    | that matters is `isMandatory()`, which is a property of the TYPE — a
    | preference switches a type off, and one type for both would mean a student
    | who muted routine notices also muted the one the teacher marked urgent.
    | Two types is also what lets the two carry different wording.
    */
    case Announcement = 'announcement';

    case AnnouncementUrgent = 'announcement_urgent';

    /*
    | Spec 021 · FR-028ح — the group transfer, and it is THREE types.
    |
    | ⚠️ APPROVED AND REJECTED ARE SEPARATE, AND THE REASON IS THE RENDERER
    | RATHER THAN TASTE. A rejection must carry the written reason the student
    | reads — that is the whole of FR-028ح — while an approval has none, and
    | `TemplateRenderer` counts a present-but-empty variable as MISSING and
    | refuses to render. One type would therefore mean one template holding
    | `{{ decision_reason }}`, and every approval on the platform would be dropped
    | in silence. It is the `penalty_note` lesson reached from the other side.
    |
    | ⚠️ AND NONE OF THEM TARGETS A GUARDIAN. A parent does not choose which
    | Saturday their child studies on, and `defaultChannels()` is derived from
    | `targetsGuardians()` — naming them there would put a paid WhatsApp message on
    | every parent's phone for a timetable preference, which is how the number
    | gets muted and the attendance alert goes with it.
    */
    case CohortTransferRequested = 'cohort_transfer_requested';

    case CohortTransferApproved = 'cohort_transfer_approved';

    case CohortTransferRejected = 'cohort_transfer_rejected';

    /*
    | The private session (023 · FR-018 · FR-023 · FR-027). Four, and each one is
    | somebody's whole knowledge of where a request got to.
    |
    | The refusal and the expiry are the two that are easy to leave out, and both
    | are the SAME failure: a request that stops being pending without saying so
    | is indistinguishable from one still waiting, so the student waits for a week
    | and then asks again — which is a queue the teacher clears twice. The
    | acceptance is the one that announces itself anyway (the lesson appears in
    | the timetable), and it is here because a student should not have to go
    | looking.
    |
    | ⚠️ THE REJECTION IS ITS OWN TYPE RATHER THAN A FLAG ON THE DECISION.
    | `TemplateRenderer` counts a present-but-empty variable as MISSING and
    | refuses to render, so one template holding `{{ decision_reason }}` would
    | drop every acceptance on the platform in silence — the `penalty_note`
    | lesson, and the same split `CohortTransferApproved`/`Rejected` already
    | needed.
    |
    | ⚠️ AND NOT ONE OF THEM TARGETS A GUARDIAN. `defaultChannels()` is DERIVED
    | from `targetsGuardians()`, so naming them there would put a paid WhatsApp
    | message on a parent's phone for every step of a scheduling conversation
    | between their child and a teacher — which is how the number gets muted, and
    | the attendance alert goes with it. No `requiredGuardianPermission()` either:
    | one without the other picks up the channel, is billed, and reaches nobody.
    */
    case PrivateSessionRequested = 'private_session_requested';

    case PrivateSessionAccepted = 'private_session_accepted';

    case PrivateSessionRejected = 'private_session_rejected';

    case PrivateSessionExpired = 'private_session_expired';

    /*
    | Spec 049 — «أجّل حصّةَ هذا الأسبوع». Three, and the middle one is the only
    | one in the family that reaches a guardian.
    |
    | ⚠️ `SessionRescheduled` TARGETS GUARDIANS BECAUSE `SessionCancelled` DOES,
    | and for the identical reason: a lesson that is no longer at the hour the
    | family arranged their day around is the same news whether it moved or
    | vanished. It carries `GuardianPermission::Schedule` in the same breath —
    | a type in `targetsGuardians()` with no permission beside it picks up the
    | paid WhatsApp channel, is billed, and reaches nobody.
    |
    | ⚠️ AND THE OTHER TWO DELIBERATELY DO NOT. The ask and the refusal are a
    | conversation between one student and their teacher about a lesson that has
    | not moved; putting every step of it on a parent's phone is how the number
    | gets muted, and the attendance alert goes with it.
    |
    | ⚠️ THE REFUSAL IS ITS OWN TYPE RATHER THAN A FLAG ON THE DECISION.
    | `TemplateRenderer` counts a present-but-empty variable as MISSING and
    | refuses to render, so one template holding `{{ decision_reason }}` would
    | drop every approval on the platform in silence.
    */
    case SessionRescheduleRequested = 'session_reschedule_requested';

    case SessionRescheduled = 'session_rescheduled';

    case SessionRescheduleRejected = 'session_reschedule_rejected';

    /*
    | The store (011 · US1). Two, and the second is the one that is easy to
    | leave out.
    |
    | A parcel changing state is news the buyer wants and the payer wants — both
    | reach a guardian on the payments consent, because ordering a printed book is
    | a purchase before it is anything else.
    |
    | ⚠️ AND `StorePurchaseUnavailable` IS MANDATORY. It is sent when the last copy
    | went while a manual bank transfer was clearing: the money has been taken and
    | is owed back. A preference that could hide it would leave somebody paid up,
    | holding nothing, with no message saying why — which is the same reason every
    | payment-path type is mandatory.
    */
    case ShipmentStatusChanged = 'shipment_status_changed';

    case StorePurchaseUnavailable = 'store_purchase_unavailable';

    /*
    | Subscriptions (011 · US4 · FR-027). «مع إبلاغ الطالب قبله بمهلة معلنة» — the
    | requirement names the STUDENT, so this deliberately does not target
    | guardians and is not mandatory. It is a reminder that a month is running
    | out, not a consequence: the access it is about has not stopped yet, and a
    | student who does not want the reminder can switch it off exactly as they can
    | switch off a shipment update.
    */
    case SubscriptionExpiring = 'subscription_expiring';

    /*
    | The subscription just started (027 · FR-029 · FR-030).
    |
    | ⚠️ IT TARGETS GUARDIANS, and that is the same argument
    | `ShipmentStatusChanged` won: it is a fact about a PURCHASE. The guardian is
    | usually who paid, and the group's timetable is what they organise their week
    | around — a schedule that reaches only the student's own feed is a schedule
    | the person driving them to the lesson never sees.
    |
    | ⚠️ AND IT IS NOT MANDATORY. Nothing is withheld and nothing has stopped; a
    | family that mutes purchase news has decided something they are allowed to
    | decide, and forcing this through would train them to mute the channel that
    | also carries the absence alert.
    */
    case SubscriptionActivated = 'subscription_activated';

    /*
    | A seat the automatic booker could not take (027 · FR-042).
    |
    | ⚠️ IT DELIBERATELY DOES NOT TARGET GUARDIANS. It is operational news for
    | the two people who can act on it — the student books an alternative, the
    | teacher widens the capacity — and a guardian can do neither. A weekly
    | notice with no action behind it is exactly how a family comes to mute the
    | channel, and the absence alert goes with it.
    */
    case SubscriptionSeatUnavailable = 'subscription_seat_unavailable';

    /*
    | The scheduled platform report (011 · US6 · FR-045).
    |
    | ⚠️ IT TARGETS NO GUARDIAN AND IS NOT MANDATORY, and both follow from who
    | receives it: the only recipients are the handful of people holding
    | `analytics.cross_teacher.view` who asked for it. A guardian has no business
    | in the platform's own figures, and a report somebody subscribed to is a
    | report they may unsubscribe from — the switch is the subscription row
    | itself.
    */
    case ScheduledReport = 'scheduled_report';

    /*
    | A viewer reported that an embedded lesson's video is broken (032 · FR-020).
    |
    | ⚠️ THE VIEWER IS THE ONLY SENSOR THERE IS. The platform cannot detect a
    | deleted or privatised video: the host answers a perfectly valid response
    | and writes its own message inside its own frame, and the browser forbids
    | reading across origins. So this type is never raised by a job or a sweep —
    | only by somebody pressing a button.
    |
    | ⚠️ IT TARGETS NO GUARDIAN, AND THAT IS WHY THE PINNED NUMBER IN
    | `WhatsAppDefaultsTest` MUST NOT MOVE. `defaultChannels()` is derived from
    | `targetsGuardians()`, so a type added there picks up a paid WhatsApp
    | message — and a broken link is a teacher's maintenance task, not news for a
    | parent. Any movement in that assertion is evidence of a mistake, never of
    | progress.
    |
    | ⚠️ AND IT IS NOT MANDATORY. Nothing is withheld and no money moved; a
    | teacher who mutes it has decided something they are allowed to decide.
    */
    case LessonLinkReported = 'lesson_link_reported';

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
            self::SessionRecordingUnavailable => 'تسجيل الحصة غير متاح',
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
            self::AssignmentSubmitted => 'تسليم واجب',
            self::AssignmentGraded => 'درجة واجب',
            self::LevelUp => 'ارتفاع المستوى',
            self::BadgeAwarded => 'شارة جديدة',
            self::RewardRedeemed => 'استبدال مكافأة',
            // Spec 013. These are the names a person sees on their own
            // preferences screen, so they say what the message is ABOUT rather
            // than naming the mechanism behind it.
            self::GuardianConsentRequired => 'طلب موافقة وليّ الأمر',
            self::DataOwnershipTransferred => 'انتقال ملكية البيانات',
            self::DataRequestCreated => 'تسلّم طلب بيانات',
            self::DataRequestCompleted => 'اكتمال طلب بيانات',
            self::GuardianConsentConflict => 'تعارض في موافقة الأولياء',
            self::TeacherOffboardingNotice => 'إخطار بمغادرة مدرّس',
            self::GuardianLinkRequested => 'طلب ارتباط بوليّ أمر',
            self::GuardianLinkDecided => 'ردّ على طلب الارتباط',
            self::ChatMessage => 'رسالة جديدة',
            self::PeriodicReviewPublished => 'تقييم دوري جديد',
            self::Announcement => 'إعلان من المدرّس',
            self::AnnouncementUrgent => 'إعلان عاجل',
            self::CohortTransferRequested => 'طلب انتقال بين المجموعات',
            self::CohortTransferApproved => 'قبول طلب الانتقال',
            self::CohortTransferRejected => 'رفض طلب الانتقال',
            self::PrivateSessionRequested => 'طلب حصة خاصة',
            self::PrivateSessionAccepted => 'قبول حصة خاصة',
            self::PrivateSessionRejected => 'رفض حصة خاصة',
            self::PrivateSessionExpired => 'انتهاء مهلة طلب حصة خاصة',
            self::SessionRescheduleRequested => 'طلب تأجيل حصة',
            self::SessionRescheduled => 'تغيير موعد حصة',
            self::SessionRescheduleRejected => 'رفض تأجيل حصة',
            self::ShipmentStatusChanged => 'تحديث شحنة',
            self::StorePurchaseUnavailable => 'طلب متجر غير متاح',
            self::SubscriptionExpiring => 'قرب انتهاء اشتراك',
            self::SubscriptionActivated => 'تفعيل اشتراك',
            self::SubscriptionSeatUnavailable => 'مقعد غير متاح',
            self::ScheduledReport => 'تقرير مجدول',
            // ⚠️ `label()` is an EXHAUSTIVE match with no default arm — a new
            // case without a line here fails static analysis before it fails a
            // test, which is the cheapest place to find out.
            self::LessonLinkReported => 'بلاغ عن رابط درس لا يعمل',
        };
    }

    /**
     * Channels used when the user has saved no preference for this type (FR-028).
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        // Spec 020 — the second channel landed, and this is the "here" the old
        // comment pointed at. Every user who never touched their settings follows
        // along, which is the whole point of a default.
        //
        // ⚠️ DERIVED FROM targetsGuardians(), NEVER A SECOND LIST OF SEVENTEEN
        // NAMES. The question "should this reach a phone?" and the question "does
        // a guardian receive this?" have the same answer for the same reason —
        // these are the messages addressed to the person who is not sitting on
        // our site. Two hand-written lists answering one question diverge at the
        // first type anybody adds, and the divergence is silent: the new type
        // simply never leaves the platform.
        //
        // ⚠️ AND security_alert IS THE ONE NAMED EXCEPTION, added deliberately.
        //
        // It is the message that says somebody else signed in as you, and the
        // bell alone reaches it only when the account holder next opens the site
        // — which, if the eviction worked, is the person who no longer can. That
        // is the one notification whose value is entirely in arriving BEFORE the
        // next visit, so it is the one type that leaves the platform without
        // targeting a guardian.
        //
        // ⚠️ AND IT IS WRITTEN HERE RATHER THAN IN targetsGuardians(), where an
        // earlier version of this comment wrongly said one word would do it.
        // That method does two other things: it fans the message out to every
        // authorised guardian, and it demands a GuardianPermission to gate it by.
        // A student's own security alert copied to their parent is a different
        // feature nobody asked for — and the sign-in it reports may well BE the
        // parent's. One word there would have shipped that silently.
        // ⚠️ AND SPEC 013 ADDS THE SECOND NAMED EXCEPTION, on the same grounds
        // and no wider. `guardian_consent_required` is addressed TO a guardian —
        // so it does not target guardians, it has one as its recipient — and
        // until they act, a child's account cannot be used at all. The bell
        // reaches them on their next visit, and a guardian whose only contact
        // with the platform is this message has no next visit. Everything else in
        // 013 is read by someone who is already on the site: they just made the
        // request, or they are the student whose own screen changed.
        return $this->targetsGuardians()
            || $this === self::SecurityAlert
            || $this === self::GuardianConsentRequired
            ? [NotificationChannel::InApp, NotificationChannel::WhatsApp]
            : [NotificationChannel::InApp];
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
            /*
            | Spec 013. Two, on the existing test: does it change what the account
            | can DO right now.
            |
            | Until a guardian consents, the child cannot sign in at all — a
            | preference that could hide that request would leave a blocked account
            | with nobody able to learn why. And a departing teacher ends access
            | the student paid for, on a deadline.
            |
            | The other four stay optional: a request receipt, a finished export
            | and a consent conflict are all read by someone who is already here.
            */
            self::GuardianConsentRequired,
            self::TeacherOffboardingNotice => true,
            /*
            | Spec 010 · FR-044. The urgent announcement, and only the urgent one.
            |
            | ⚠️ AND MANDATORY BUYS IT EXACTLY ONE THING HERE, WHICH IS NOT WHAT
            | THE REQUIREMENT'S WORDING SUGGESTS. Quiet hours and digesting apply
            | to EXTERNAL channels alone (`QuietHours::deferUntil()` returns null
            | for anything else), and an announcement reaches the bell and nothing
            | else — so «past quiet hours» is already true of both types and says
            | nothing about either. What this flag actually does is put the type
            | into `mandatoryValues()`, which is the list spec 009's focus timer
            | reads: an urgent notice reaches a student mid-study session, and a
            | routine one waits for them to finish. It also stops a preference
            | switching it off. Those two are the whole difference, and they are
            | the right two.
            */
            self::AnnouncementUrgent => true,
            // Spec 011 · FR-006ب. The money has been taken and is owed back. A
            // preference that hid it would leave somebody paid up, holding
            // nothing, with no message saying why. `ShipmentStatusChanged` is
            // deliberately NOT here — «جُهّز» is news, not a consequence.
            self::StorePurchaseUnavailable => true,
            default => false,
        };
    }

    /**
     * The types no preference and no mute may suppress.
     *
     * Used by spec 009's focus timer, which hides the optional traffic while a
     * student is studying — these pass through, because each of them changes what
     * the account can do right now.
     *
     * @return list<string>
     */
    public static function mandatoryValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isMandatory()),
        ));
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
            // 049. A lesson moved to another hour is the same fact for the
            // family as a lesson called off — the day was arranged around it
            // either way, and only the guardian can rearrange the rest of it.
            self::SessionRescheduled,
            // A mark is a result, and the guardian asking how their child is
            // doing is asking exactly this. Gated on the same permission as
            // ExamResult below, because it is the same kind of fact.
            self::AssignmentGraded,
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
            self::PaymentReversed,
            // Spec 009. A redeemed reward can be a discount on a session, which
            // changes what the family pays — so the person who pays hears about
            // it. Levels and badges deliberately do NOT: several a week on a
            // guardian's phone is how the number gets muted, taking the
            // attendance alert with it.
            self::RewardRedeemed,
            /*
            | Spec 013. Two, and the reasoning for each is that the guardian is a
            | PARTY to the fact rather than an observer of it.
            |
            | The ownership transfer is the moment the guardian STOPS being able to
            | ask for the record — telling only the student would leave the person
            | losing the access as the one person not told they had lost it.
            |
            | The offboarding notice is the student's teacher leaving, which ends a
            | paid arrangement the guardian made and usually pays for.
            |
            | ⚠️ AND BOTH HAVE A `requiredGuardianPermission()` BELOW. One without
            | the other reaches no guardian at all while still picking up the
            | WhatsApp channel — a message that leaves the platform, is billed, and
            | arrives nowhere.
            */
            self::DataOwnershipTransferred,
            self::TeacherOffboardingNotice,
            // Spec 010. The guardian is the audience as much as the student is —
            // FR-029 names them both, and an assessment nobody at home reads is
            // the report card left in the school bag.
            self::PeriodicReviewPublished,
            // Spec 011. A parcel and a refund are both facts about a purchase,
            // and the guardian is usually the person who made it.
            self::ShipmentStatusChanged,
            self::StorePurchaseUnavailable,
            self::SubscriptionActivated => true,
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
            // The same consent as an exam result, because it is the same fact
            // about the same child in a different shape.
            self::AssignmentGraded => GuardianPermission::Results,
            self::AcademicWarning => GuardianPermission::AcademicWarnings,
            // The post-session report is attendance news before it is
            // anything else, so it rides the guardian's attendance consent.
            self::SessionReport => GuardianPermission::Attendance,
            self::SessionCancelled => GuardianPermission::Schedule,
            self::SessionRescheduled => GuardianPermission::Schedule,
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
            self::PaymentReversed,
            /*
            | ⚠️ WITHOUT THIS LINE, `targetsGuardians()` WOULD BE A LIE THAT COSTS
            | MONEY. RecipientResolver merges guardians only when the type targets
            | them AND names a permission — so a type in the list above with no
            | mapping here reaches NO guardian at all, while still picking up the
            | WhatsApp channel from defaultChannels(). The message would leave the
            | platform, be billed, and arrive nowhere it was added for.
            */
            self::RewardRedeemed => GuardianPermission::Payments,
            // Spec 013. The transfer is a data-rights fact and rides the data-rights
            // consent; the offboarding notice is the schedule ending, which is what
            // `Schedule` already gates for a cancelled session.
            self::DataOwnershipTransferred => GuardianPermission::DataRights,
            self::TeacherOffboardingNotice => GuardianPermission::Schedule,
            // Spec 010. The same consent as an exam result and a graded
            // assignment, because it is the same kind of fact about the same child.
            self::PeriodicReviewPublished => GuardianPermission::Results,
            // Spec 011. Both are purchases before they are anything else, so
            // they ride the same consent the payment path already uses.
            self::ShipmentStatusChanged,
            self::StorePurchaseUnavailable,
            self::SubscriptionActivated => GuardianPermission::Payments,
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
