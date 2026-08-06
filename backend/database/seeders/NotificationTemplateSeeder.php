<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Seeder;

/**
 * One in-app template per type. Editable afterwards from the admin panel without
 * a deploy (FR-036) — this only supplies the starting wording.
 *
 * updateOrCreate on (type, channel): re-seeding must not duplicate, and must not
 * silently overwrite an admin's edits with the shipped default. It does overwrite
 * here, which is correct for `migrate:fresh --seed` and wrong for production —
 * which is why the seeder is not part of any deployment path.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $type => [$title, $body, $variables]) {
            $notificationType = NotificationType::from($type);

            MessageTemplate::query()->updateOrCreate(
                [
                    'type' => $notificationType->value,
                    'channel' => NotificationChannel::InApp->value,
                ],
                [
                    'key' => MessageTemplate::keyFor($notificationType, NotificationChannel::InApp),
                    'title_ar' => $title,
                    'body_ar' => $body,
                    'variables' => $variables,
                    'provider_approval_status' => MessageTemplate::APPROVAL_NOT_REQUIRED,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Title, body, and the variables the body requires.
     *
     * A variable listed here and missing at send time refuses the message rather
     * than rendering a gap (FR-037) — so the list is exactly what the body reads,
     * not everything the caller happens to pass.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function templates(): array
    {
        return [
            NotificationType::SessionReport->value => [
                'تقرير حصة {{ title }}',
                'حالة {{ student_name }} في حصة «{{ title }}»: {{ status }}، مدة الحضور {{ minutes }} دقيقة. {{ note }}',
                ['title', 'student_name', 'status', 'minutes', 'note'],
            ],
            // Worded for both endings a booked seat can have — called off by the
            // teacher, or suspended by a freeze. "لن تُعقد" is true of each; a
            // template saying "أُلغيت" would tell a family their holiday was a
            // cancellation.
            NotificationType::SessionCancelled->value => [
                'حصة {{ title }} لن تُعقد',
                'لن تُعقد حصة «{{ title }}» المقرّرة في {{ starts_at }}. السبب: {{ reason }}.',
                ['title', 'starts_at', 'reason'],
            ],
            NotificationType::SessionRecordingFailed->value => [
                'تعذّر نشر تسجيل الحصة',
                'لم يُنشر تسجيل حصة «{{ title }}» بعد عدّة محاولات ({{ reason }}). يمكنك رفعه يدوياً من صفحة الحصة.',
                ['title', 'reason'],
            ],
            // Settlement (014). The teacher's own contract: a decision on their
            // rate, a period closing, money leaving. None of these names a
            // student, a payment or a sale price — FR-018 forbids all three in
            // anything that reaches a teacher, and a notification body is a
            // payload like any other.
            NotificationType::SettlementRateApproved->value => [
                'اعتُمد سعر تسويتك الجديد',
                'اعتُمد سعرك الجديد {{ amount }} لحصص {{ session_type }}، ويسري من {{ effective_from }} على ما بعده. الوحدات المُنفَّذة قبل هذا التاريخ تبقى بسعرها.',
                ['amount', 'session_type', 'effective_from'],
            ],
            NotificationType::SettlementRateRejected->value => [
                'لم يُعتمد طلب تغيير السعر',
                'لم يُعتمد طلبك لسعر {{ amount }}. السبب: {{ reason }}. سعرك الحالي ساري كما هو.',
                ['amount', 'reason'],
            ],
            NotificationType::SettlementPeriodClosed->value => [
                'أُغلقت فترة تسويتك',
                'أُغلقت فترة {{ starts_on }} — {{ ends_on }}: {{ units_count }} وحدة، والصافي {{ net }}. تفاصيلها في كشفك.',
                ['starts_on', 'ends_on', 'units_count', 'net'],
            ],
            NotificationType::TeacherPayoutIssued->value => [
                'نُفِّذ صرف مستحقّك',
                'نُفِّذ صرف بمبلغ {{ amount }} بمرجع {{ reference }}. يظهر في سجلّ صرفك.',
                ['amount', 'reference'],
            ],
            NotificationType::EnrollmentCreated->value => [
                'تم تسجيلك في كورس',
                'مرحباً {{ name }}، تم تسجيلك في «{{ course_title }}». يمكنك البدء الآن.',
                ['name', 'course_title'],
            ],
            NotificationType::CertificateIssued->value => [
                'صدرت شهادتك',
                'مبارك {{ name }}! صدرت شهادتك رقم {{ certificate_number }} عن «{{ course_title }}».',
                ['name', 'certificate_number'],
            ],
            NotificationType::CertificateRegenerated->value => [
                'أُعيد إصدار شهادتك',
                'تم إعادة إصدار شهادتك رقم {{ certificate_number }}. النسخة السابقة لم تعد سارية.',
                ['certificate_number'],
            ],
            NotificationType::TeacherApplicationApproved->value => [
                'تم قبول طلبك للتدريس',
                'تهانينا {{ name }}، راجع فريقنا الأكاديمي طلبك ووافق عليه. {{ listing_state }}',
                ['name', 'listing_state'],
            ],
            NotificationType::TeacherApplicationRejected->value => [
                'لم يُقبل طلبك للتدريس',
                'نأسف {{ name }}، لم يُقبل طلبك. السبب: {{ reason }}',
                ['name', 'reason'],
            ],
            NotificationType::TeacherApplicationChangesRequested->value => [
                'مطلوب تعديل على طلبك',
                'مرحباً {{ name }}، طلبك يحتاج تعديلاً قبل المراجعة. المطلوب: {{ reason }}',
                ['name', 'reason'],
            ],
            NotificationType::SecurityAlert->value => [
                'تنبيه أمني على حسابك',
                'مرحباً {{ name }}، {{ event }} إن لم يكن هذا أنت فغيّر كلمة مرورك فوراً.',
                ['name', 'event'],
            ],
            // The five guardian-facing types below have no producer yet; theirs
            // arrive with specs 005, 006 and 008. Their templates ship now so
            // those phases add a listener and nothing else.
            NotificationType::AttendanceAlert->value => [
                'تنبيه حضور',
                'لم يحضر {{ student_name }} حصّة {{ session_title }} بتاريخ {{ session_date }}.',
                ['student_name', 'session_title', 'session_date'],
            ],
            NotificationType::PaymentReminder->value => [
                'تذكير بمستحقّ',
                'على حساب {{ student_name }} مستحقّ بقيمة {{ amount }}. يرجى السداد لمواصلة الحصص.',
                ['student_name', 'amount'],
            ],
            NotificationType::AppointmentReminder->value => [
                'تذكير بموعد حصّة',
                'حصّة {{ student_name }} في {{ session_title }} تبدأ {{ starts_at }}.',
                ['student_name', 'session_title', 'starts_at'],
            ],
            NotificationType::ExamResult->value => [
                'صدرت نتيجة اختبار',
                'نتيجة {{ student_name }} في «{{ exam_title }}»: {{ score }}.',
                ['student_name', 'exam_title', 'score'],
            ],
            NotificationType::AcademicWarning->value => [
                'إنذار أكاديمي',
                'مستوى {{ student_name }} في «{{ course_title }}» يحتاج متابعة. {{ note }}',
                ['student_name', 'course_title', 'note'],
            ],
        ];
    }
}
