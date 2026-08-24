<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Notifications\Channels\WhatsAppChannel;
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
 *
 * ⚠️ AND THAT LEFT A HOLE THE SIZE OF EVERY NEW TYPE, closed by `seedMissing()`
 * below rather than by putting this in a deployment. See its docblock: a type
 * added on a live database has no template, and a notification with no template
 * is dropped in silence.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->write(overwrite: true);
    }

    /**
     * Write only the rows that are MISSING, and touch nothing that exists.
     *
     * ⚠️ THIS EXISTS BECAUSE `run()` MAY NOT BE PART OF A DEPLOYMENT AND A NEW
     * TYPE MUST STILL LAND. The docblock above says why: `updateOrCreate` is
     * correct for `migrate:fresh --seed` and would silently replace an admin's
     * edited wording on every production deploy. So a new `NotificationType`
     * arrives on a live database with no template — and `TemplateRenderer`
     * refuses a missing one while `DispatchNotification` logs and does not fail,
     * so the notification is dropped in **silence**.
     *
     * Measured, not reasoned about: spec 010's first live announcement reached
     * ZERO of three students on a database whose migrations were up to date, and
     * nothing anywhere reported a fault. A backfill migration calls this.
     *
     * `firstOrCreate` rather than `updateOrCreate` is the whole difference, and
     * it is what makes running this twice — or on a database an admin has been
     * editing for a year — harmless.
     */
    public function seedMissing(): void
    {
        $this->write(overwrite: false);
    }

    private function write(bool $overwrite): void
    {
        foreach ($this->templates() as $type => [$title, $body, $variables]) {
            $notificationType = NotificationType::from($type);

            $match = [
                'type' => $notificationType->value,
                'channel' => NotificationChannel::InApp->value,
            ];

            $values = [
                'key' => MessageTemplate::keyFor($notificationType, NotificationChannel::InApp),
                'title_ar' => $title,
                'body_ar' => $body,
                'variables' => $variables,
                'provider_approval_status' => MessageTemplate::APPROVAL_NOT_REQUIRED,
                'is_active' => true,
            ];

            $overwrite
                ? MessageTemplate::query()->updateOrCreate($match, $values)
                : MessageTemplate::query()->firstOrCreate($match, $values);

            // Spec 020. A WhatsApp row for every type that defaults to it, and
            // the set is DERIVED from the same predicate the default is derived
            // from — so a type added tomorrow gets its row on the next seed
            // instead of being dropped in silence by TemplateRenderer.
            if (in_array(NotificationChannel::WhatsApp, $notificationType->defaultChannels(), true)) {
                $this->whatsAppTemplate($notificationType->value, $title, $body, $variables, $overwrite);
            }
        }

        /*
         * The one WhatsApp template that is not a NotificationType.
         *
         * ⚠️ AND THE ONE THAT MUST BE APPROVED FIRST. It carries the one-time
         * code, so until the provider approves it no number on the platform can
         * be verified — and canReach() is false for everybody, so not one of the
         * nineteen above ever leaves the building. Approving the others first
         * buys nothing at all.
         */
        $this->whatsAppTemplate(
            WhatsAppChannel::VERIFICATION_TEMPLATE_TYPE,
            'رمز تأكيد رقم واتساب',
            'رمز تأكيد رقمك في منصّة مدارك هو {{ code }}. ينتهي خلال عشر دقائق.',
            ['code'],
            $overwrite,
        );
    }

    /**
     * ⚠️ THE TEXT HERE IS DOCUMENTATION, NOT THE MESSAGE.
     *
     * What a phone displays is the wording registered and approved at the
     * provider; this row exists for the two things a send cannot happen without —
     * the ORDER of `variables`, which is what every numbered placeholder in the
     * approved template means, and `provider_approval_status`, which is how the
     * system learns the outcome of a human process that happens outside it.
     * Editing `body_ar` from the admin panel changes the notification bell and
     * changes nothing on WhatsApp.
     *
     * Seeded PENDING, never APPROVED. Claiming an approval that has not happened
     * turns the first send into a provider error code nobody can map back to a
     * row, instead of a refusal that names the template key in Arabic.
     *
     * @param  list<string>  $variables
     */
    private function whatsAppTemplate(string $type, string $title, string $body, array $variables, bool $overwrite = true): void
    {
        $writer = $overwrite ? 'updateOrCreate' : 'firstOrCreate';

        MessageTemplate::query()->{$writer}(
            [
                'type' => $type,
                'channel' => NotificationChannel::WhatsApp->value,
            ],
            [
                'key' => $type.'.'.NotificationChannel::WhatsApp->value,
                'title_ar' => $title,
                'body_ar' => $body,
                'variables' => $variables,
                'provider_approval_status' => MessageTemplate::APPROVAL_PENDING,
                'is_active' => true,
            ],
        );
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
            /*
             * ⚠️ THE TEMPLATE IS NOT DECORATION — WITHOUT IT THE NOTIFICATION IS
             * DROPPED IN SILENCE. TemplateRenderer refuses a missing or unapproved
             * template and DispatchNotification logs rather than fails (003
             * FR-037), so a test asserting the dispatch was CALLED passes green on
             * zero notifications delivered. Which is why SC-013 counts rows.
             *
             * No provider reason in the student's copy, deliberately: it names a
             * system they have no access to and cannot act on.
             */
            NotificationType::SessionRecordingUnavailable->value => [
                'تسجيل الحصة غير متاح',
                'تعذّر نشر تسجيل حصة «{{ title }}». تواصل مع مدرّسك إن كنت بحاجة إليه.',
                ['title'],
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
            /*
            | Credits (006). Every body names the COURSE, because withholding is
            | per course: a student who owes for physics keeps their maths notes,
            | and a message that said only "رصيدك" would read as though both had
            | stopped. And every one of them ends at what to DO — FR-032 asks for
            | the amount needed and the way to pay, not a statement of fact.
            |
            | No price anywhere. The credit count is what the student holds; the
            | riyals are on the purchase screen, where the platform sets them.
            */
            NotificationType::CreditBalanceLow->value => [
                'اقترب رصيدك من النفاد في {{ course }}',
                'بقيت لك {{ credits }} حصة في «{{ course }}». يمكنك شراء المزيد قبل أن تنفد.',
                ['course', 'credits'],
            ],
            NotificationType::CreditBalanceCritical->value => [
                'رصيد {{ course }} على وشك النفاد',
                'لم يبق في «{{ course }}» سوى {{ credits }} حصة. جدّد الرصيد كي لا يتوقّف الحجز.',
                ['course', 'credits'],
            ],
            // The one a student reads while locked out, so it carries the reason,
            // the number and the way back — in that order.
            NotificationType::AccessWithheld->value => [
                'أُوقف الحجز في {{ course }}',
                'رصيدك في «{{ course }}» لم يعد يكفي، فتوقّف حجز الحصص الجديدة. المطلوب {{ credits_needed }} حصة على الأقل لاستئنافه، وتُشترى من صفحة الأرصدة.',
                ['course', 'credits_needed'],
            ],
            NotificationType::AccessRestored->value => [
                'استُؤنف الحجز في {{ course }}',
                'عاد رصيدك في «{{ course }}» إلى ما يكفي، ويمكنك الحجز الآن. رصيدك الحالي {{ credits }} حصة.',
                ['course', 'credits'],
            ],
            /*
            | A reminder, never a notice of expiry (Q-8). The credits stay, and
            | the message says so — a message that hinted otherwise would be the
            | platform quietly inventing an expiry it does not have.
            */
            NotificationType::CreditBalanceDormant->value => [
                'لديك رصيد غير مستخدَم في {{ course }}',
                'لم تستخدم رصيدك في «{{ course }}» منذ {{ months }} شهراً، وما زالت لديك {{ credits }} حصة. الرصيد لا ينتهي، ويمكنك استخدامه في أي وقت أو طلب استرداده.',
                ['course', 'credits', 'months'],
            ],
            /*
            | Spec 008. Written as a summary rather than "your import is done",
            | because the three numbers are the whole answer for the teacher whose
            | file went through cleanly — and the only ones who need to open the
            | report are those whose `failed` is not zero.
            */
            NotificationType::QuestionImportReady->value => [
                'اكتمل استيراد {{ filename }}',
                'اكتمل استيراد الأسئلة من «{{ filename }}»: أُضيف {{ imported }} سؤالاً، وتُخطّي {{ skipped }}، وتعذّر {{ failed }}. افتح التقرير لمعرفة سبب كل صفّ لم يُضَف.',
                ['filename', 'imported', 'skipped', 'failed'],
            ],
            NotificationType::QuestionImportFailed->value => [
                'تعذّر استيراد {{ filename }}',
                'لم يكتمل استيراد الأسئلة من «{{ filename }}»: {{ reason }}. لم يُضَف أيّ سؤال، ويمكنك تصحيح الملف ورفعه من جديد.',
                ['filename', 'reason'],
            ],
            /*
            | ⚠️ NO SCORE IN THIS ONE, DELIBERATELY. The attempt already holds the
            | machine-marked total, and quoting it here would tell a student who
            | wrote perfect essays that they scored 40 — a true number that is not
            | their result. The whole message is "not yet".
            */
            /*
            | Homework. The graded one names the penalty, and `penalty_note` is
            | never empty: TemplateRenderer counts present-but-empty as missing
            | and refuses to render, so an "omit it when there is nothing to say"
            | clause would drop the whole message for every student who handed in
            | on time. A mark lower than expected with no stated cause is the
            | message a student replies to; a message that never arrives is worse.
            */
            NotificationType::AssignmentSubmitted->value => [
                'تسليم جديد في «{{ assignment_title }}»',
                'سلّم {{ student_name }} واجب «{{ assignment_title }}». افتح اللوحة لتصحيحه.',
                ['student_name', 'assignment_title'],
            ],
            NotificationType::AssignmentGraded->value => [
                'صُحّح واجب «{{ assignment_title }}»',
                'درجة {{ student_name }} في واجب «{{ assignment_title }}»: {{ score }} من {{ points }}. {{ penalty_note }}',
                ['student_name', 'assignment_title', 'score', 'points', 'penalty_note'],
            ],
            /*
            | Gamification (009). Bell only, and student only.
            |
            | ⚠️ NO NUMBER OF COINS IN EITHER OF THEM. Coins are not money, but the
            | rule that keeps prices off a student's screen is the same rule that
            | keeps a running total off it: a purse belongs to one teacher, so any
            | figure a message quotes is ambiguous the moment the student studies
            | with a second one. The profile screen shows the split; a message says
            | what happened.
            */
            NotificationType::LevelUp->value => [
                'وصلت إلى مستوى {{ level_name }}',
                'مبروك {{ student_name }}! خبرتك أوصلتك إلى مستوى «{{ level_name }}». واصِل.',
                ['student_name', 'level_name'],
            ],
            NotificationType::BadgeAwarded->value => [
                'شارة جديدة: {{ badge_name }}',
                'حصل {{ student_name }} على شارة «{{ badge_name }}».',
                ['student_name', 'badge_name'],
            ],
            /*
            | The one gamification type that reaches a guardian, and therefore the
            | one with a WhatsApp row — template nineteen.
            |
            | ⚠️ NO PRICE AND NO COIN COUNT. What the reward cost the teacher is
            | their business, and a coin figure is ambiguous the moment the student
            | studies with a second teacher.
            */
            NotificationType::RewardRedeemed->value => [
                'استُبدلت مكافأة: {{ reward_title }}',
                'استبدل {{ student_name }} مكافأة «{{ reward_title }}» من متجر {{ teacher_name }}، وهي بانتظار التنفيذ.',
                ['student_name', 'reward_title', 'teacher_name'],
            ],

            /*
            | Spec 013 — data protection. Six rows, seeded APPROVED for the in-app
            | channel like every other type here.
            |
            | ⚠️ WITHOUT THEM THE SIX DISPATCH POINTS ARE DROPPED IN SILENCE and
            | every assertion about them passes by finding nothing —
            | `TemplateRenderer` refuses an unknown template and
            | `DispatchNotification` logs rather than failing the operation behind
            | it. `NotificationTemplateCoverageTest` is what keeps this list from
            | falling behind the enum.
            |
            | ⚠️ AND NOT ONE OF THEM NAMES WHO REFUSED. The conflict message goes
            | to BOTH guardians, who may be in a custody dispute — «رفضت والدتك»
            | in an automated message is personal data about a third party, sent by
            | us, in writing.
            */
            NotificationType::GuardianConsentRequired->value => [
                'حساب {{ student_name }} بانتظار موافقتك',
                'سجّل {{ student_name }} حساباً على المنصّة، ولأنّه دون الثامنة عشرة لا يُفعَّل الحساب قبل موافقتك على معالجة بياناته. افتح صفحة «المرتبطون» لقراءة ما يُجمَع ولماذا.',
                ['student_name'],
            ],
            NotificationType::DataOwnershipTransferred->value => [
                'صارت بياناتك ملكَك',
                'بلغ {{ student_name }} الثامنةَ عشرة، فانتقلت إليه ملكيةُ بياناته: هو وحده من يوافق على معالجتها ويطلب نسخةً منها أو حذفَها. لم ينقطع شيءٌ من الخدمة.',
                ['student_name'],
            ],
            NotificationType::DataRequestCreated->value => [
                'تسلّمنا طلبك: {{ request_type }}',
                'تسلّمنا طلبَ {{ request_type }} الخاصَّ بـ{{ student_name }}. سنردّ عليه قبل {{ due_date }}، وسنُعلمك حين يكتمل.',
                ['student_name', 'request_type', 'due_date'],
            ],
            NotificationType::DataRequestCompleted->value => [
                'اكتمل طلبك: {{ request_type }}',
                'اكتمل طلبُ {{ request_type }} الخاصُّ بـ{{ student_name }}. افتح صفحة «خصوصيّتي» لتنزيل الملفّ — الرابطُ صالحٌ لمدّةٍ قصيرة، ثمّ يُحذَف الملفّ.',
                ['student_name', 'request_type'],
            ],
            NotificationType::GuardianConsentConflict->value => [
                'تعارضٌ في الموافقة على بيانات {{ student_name }}',
                'تلقّينا قرارَين مختلفَين بشأن معالجة بيانات {{ student_name }} من وليَّي أمرٍ مُخوَّلَين. نأخذ بالرفض إلى أن يتّفق الطرفان، فالموافقةُ يمكن منحُها لاحقاً وما نُشر لا يمكن سحبُه.',
                ['student_name'],
            ],
            NotificationType::TeacherOffboardingNotice->value => [
                'المدرّس {{ teacher_name }} يغادر المنصّة',
                'أبلغَنا {{ teacher_name }} برغبته في إنهاء نشاطه على المنصّة. يبقى ما دفعتَ له متاحاً حتى {{ notice_end_date }}، ولن تُجدوَل حصصٌ جديدة.',
                ['teacher_name', 'notice_end_date'],
            ],
            /*
            | Spec 010 — a message arrived while you were not looking.
            |
            | ⚠️ THE BODY CARRIES NO PART OF THE MESSAGE, and that is not
            | squeamishness: a notification row is read by the bell, is exported in
            | a data request, and is the one copy of these words that outlives a
            | hide. The sender's name and the way back in are what the recipient
            | needs; the words themselves live in the thread, behind the policy.
            */
            NotificationType::ChatMessage->value => [
                'رسالة جديدة من {{ sender_name }}',
                'وصلتك رسالة جديدة من {{ sender_name }}. افتح المحادثة لقراءتها والردّ عليها.',
                ['sender_name'],
            ],
            /*
            | The periodic assessment (010 · FR-029).
            |
            | ⚠️ NO AXIS AND NO NUMBER IN THE BODY, and the reason is the guardian:
            | this template travels to WhatsApp, where it is one approved sentence
            | for every family on the platform. A message carrying «٢ من ٥ في
            | الواجبات» is a mark delivered to a phone with no context beside it
            | and no way for the student to answer it. The message says an
            | assessment exists and where to read it; the four axes and the
            | teacher's note live on the screen behind the link.
            */
            NotificationType::PeriodicReviewPublished->value => [
                'تقييم {{ student_name }} الدوري',
                'نشر {{ teacher_name }} تقييماً دورياً لـ{{ student_name }} عن الفترة من {{ period_start }} إلى {{ period_end }}. افتح التقييم لقراءته.',
                ['student_name', 'teacher_name', 'period_start', 'period_end'],
            ],
            /*
            | The teacher's announcement (010 · FR-042 … FR-044).
            |
            | ⚠️ AND THIS IS THE ONE TEMPLATE WHOSE BODY IS THE WHOLE MESSAGE,
            | which is the opposite of the chat rule six lines above. Nothing else
            | delivers an announcement — there is no student announcements screen
            | and FR-044 says the notification centre IS the delivery — so a body
            | naming the teacher and linking elsewhere would be a notice that
            | announces nothing. The chat template withholds its words because the
            | thread is behind a policy and the row outlives a hide; an
            | announcement has no thread to go back to.
            |
            | Safe to inline because these two are the bell alone. A template that
            | left the platform would be an approved WhatsApp sentence with a
            | teacher's free text substituted into it, which no provider approves.
            */
            NotificationType::Announcement->value => [
                'إعلان من {{ teacher_name }}',
                '{{ body }}',
                ['teacher_name', 'body'],
            ],
            NotificationType::AnnouncementUrgent->value => [
                'إعلان عاجل من {{ teacher_name }}',
                '{{ body }}',
                ['teacher_name', 'body'],
            ],
            NotificationType::ExamPendingGrading->value => [
                'تسلّمنا ورقتك في «{{ exam_title }}»',
                'تسلّمنا ورقة {{ student_name }} في «{{ exam_title }}». فيها أسئلة مقالية ينتظر تصحيحُها المدرّس، وتصلك النتيجة كاملةً بعده.',
                ['student_name', 'exam_title'],
            ],
            /*
            | The payment path (007).
            |
            | ⚠️ NO AMOUNT IN ANY OF THEM, and that is not an omission. A credit's
            | price is the teacher's approved settlement rate plus two platform
            | constants, so a total sent to a student — or to their guardian —
            | is solvable for the teacher's rate across two package sizes
            | (FR-035). The message names the COURSE and what changed; the number
            | lives on the billing screen, which is the platform's own surface.
            |
            | And a failure says what to do next. «تعذّر الدفع» alone leaves the
            | reader unable to tell a wrong card from a platform outage.
            */
            NotificationType::PaymentConfirmed->value => [
                'وصلت دفعتك',
                'استلمنا دفعتك عن «{{ course }}»، وأُضيف رصيدك. يمكنك الحجز الآن.',
                ['course'],
            ],
            NotificationType::PaymentFailed->value => [
                'تعذّر إتمام الدفع',
                'لم تكتمل عملية الدفع عن «{{ course }}». السبب: {{ reason }}. لم يُخصم منك شيء، ويمكنك المحاولة مرة أخرى من صفحة الأرصدة.',
                ['course', 'reason'],
            ],
            NotificationType::ReceiptApproved->value => [
                'اعتُمد إيصالك',
                'راجع الفريق إيصالك عن «{{ course }}» واعتمده، وأُضيف رصيدك.',
                ['course'],
            ],
            NotificationType::ReceiptRejected->value => [
                'لم يُعتمد الإيصال',
                'لم يُعتمد الإيصال المرفوع عن «{{ course }}». السبب: {{ reason }}. يمكنك رفع إيصال آخر من صفحة الطلب.',
                ['course', 'reason'],
            ],
            NotificationType::PaymentReversed->value => [
                'أُعيدت دفعة سابقة',
                'أُعيدت دفعة سابقة كنت قد سدّدتها. السبب: {{ reason }}. قد يتوقّف الحجز حتى تُسوّى، وفريق الأكاديمية على تواصل معك.',
                ['reason'],
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
