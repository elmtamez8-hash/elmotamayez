<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Notifications\Channels\WhatsAppChannel;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Database\TranslatableColumns;
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
        // The conversion migration has not run yet — see {@see TranslatableColumns::converted}.
        if (! TranslatableColumns::converted('message_templates', 'title')) {
            return;
        }

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
                'title' => $title,
                'body' => $body,
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
     * Editing `body` from the admin panel changes the notification bell and
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
                'title' => $title,
                'body' => $body,
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
     * ⚠️ AND IN THE ORDER THE BODY FIRST READS THEM — not the title. WhatsApp
     * sends `variables` as the body's numbered parameters, in this order, so a
     * list that follows the title put the months where the credits belong
     * (`credit_balance_dormant`, fixed 2026-09-24 with two siblings).
     * `TemplateVariableOrderTest` fails the build over the next one.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function templates(): array
    {
        return [
            NotificationType::SessionReport->value => [
                'تقرير حصة {{ title }}',
                'حالة {{ student_name }} في حصة «{{ title }}»: {{ status }}، مدة الحضور {{ minutes }} دقيقة. {{ note }}',
                ['student_name', 'title', 'status', 'minutes', 'note'],
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
                ['course', 'months', 'credits'],
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
            /*
            | 021 · FR-028ح. Three rows, and the rejection is the one the
            | requirement is actually about: «الرفضُ يحملُ سبباً مكتوباً يقرأُه
            | الطالب». `decision_reason` is mandatory in the Action, so it can
            | never arrive empty here — which is what lets it sit in the body at
            | all, since a present-but-empty variable makes TemplateRenderer
            | refuse the whole message.
            |
            | ⚠️ AND THE APPROVAL SAYS THE SEATS WERE GIVEN UP. Moving group
            | cancels the student's future bookings in the one they left (FR-030);
            | a congratulation that does not mention it leaves them expecting a
            | lesson that is no longer theirs.
            */
            NotificationType::CohortTransferRequested->value => [
                'طلب انتقال في «{{ course_title }}»',
                'يطلب {{ student_name }} الانتقال من «{{ from_cohort }}» إلى «{{ to_cohort }}». افتح مجموعات الكورس للبتّ في الطلب.',
                ['student_name', 'course_title', 'from_cohort', 'to_cohort'],
            ],
            NotificationType::CohortTransferApproved->value => [
                'قُبل انتقالك إلى «{{ to_cohort }}»',
                'وافق مدرّسك على انتقالك إلى مجموعة «{{ to_cohort }}» في «{{ course_title }}». مواعيدك الجديدة في جدولك، وحجوزاتك القادمة في مجموعتك السابقة أُلغيت.',
                ['to_cohort', 'course_title'],
            ],
            NotificationType::CohortTransferRejected->value => [
                'لم يُقبل انتقالك إلى «{{ to_cohort }}»',
                'لم يوافق مدرّسك على انتقالك إلى «{{ to_cohort }}» في «{{ course_title }}». السبب: {{ decision_reason }} — وما زلت في مجموعتك الحالية بكامل حقوقك.',
                ['to_cohort', 'course_title', 'decision_reason'],
            ],
            /*
            | ٠٣٤ · FR-006 · FR-008 — الإدارةُ أسنَدَت.
            |
            | ⚠️ **المواعيدُ في المتن، لا رابطٌ إليها**: الطالبُ يقرأُ هذا على
            | هاتفِه ليعرفَ متى درسُه القادم، ورسالةٌ تقولُ «افتحْ جدولَك» رحلةٌ
            | ثانيةٌ إلى الشاشةِ لتُجيبَ السؤالَ الذي أُرسِلَت لأجلِه.
            |
            | ⚠️ **و«لم تُعلَنْ مواعيدُها بعد» تُقالُ صراحةً**: سطرٌ محذوفٌ يُقرَأُ
            | عُطلاً — السابقةُ مكتوبةٌ في `ActivateSubscription` (٠٢٧ · FR-029أ).
            */
            NotificationType::CohortAssigned->value => [
                'أُسندت إلى مجموعة «{{ cohort_name }}»',
                'أسندتك إدارة المنصّة إلى مجموعة «{{ cohort_name }}» في «{{ course_title }}». {{ schedule }}',
                ['cohort_name', 'course_title', 'schedule'],
            ],
            /*
            | ⚠️ **ليسَ رفضاً، والفرقُ هو سببُ وجودِ النوعِ منفصلاً.** الرفضُ قرارٌ
            | اتُّخِذَ في الطلب؛ وهذا أنّ الطلبَ فقدَ معناه. ولولا الجملةُ لقرأَ
            | الطالبُ اختفاءَ طلبِه عُطلاً وأعادَ إرسالَه (٠٢١ · FR-028ح).
            */
            /*
            | ٠٣٤ · FR-029 — فُتِحَ مكانٌ ودَورُك جاء.
            |
            | ⚠️ **والرسالةُ تقولُ إنّ المقعدَ ليسَ محجوزاً.** الدَّورُ لا يحجزُ
            | ولا يَعِدُ (FR-027)، ودعوةٌ تُقرَأُ «مقعدُك جاهز» تُخلِفُ الوعدَ
            | لمن يتأخّرُ يوماً ويجدُ المكانَ قد ذهب.
            */
            NotificationType::WaitlistInvited->value => [
                'فُتح مكان في «{{ course_title }}»',
                'جاء دورك في «{{ course_title }}»: فُتح مكان في مجموعة «{{ cohort_name }}». افتحِ الكورس لتكمل — المقعد ليس محجوزاً لك، وهو لمن يسبق.',
                ['course_title', 'cohort_name'],
            ],
            NotificationType::CohortTransferRequestDropped->value => [
                'سقط طلب انتقالك في «{{ course_title }}»',
                'أسندتك إدارة المنصّة إلى مجموعة «{{ cohort_name }}» في «{{ course_title }}»، فلم يعد لطلب انتقالك السابق محلّ وأُغلق. إن أردت مجموعة أخرى فأرسل طلباً جديداً.',
                ['course_title', 'cohort_name'],
            ],
            /*
            | The private session (023). Four rows, and the two refusals are the
            | ones a reader is tempted to fold into one: an expiry is «nobody
            | answered» and a rejection is «somebody did» — a student who reads
            | the wrong one waits for a teacher who already said no, or chases a
            | teacher who never saw the request.
            |
            | ⚠️ THE DURATION IS IN THE TEXT, NEVER A PRICE. FR-028: a credit's
            | price is the teacher's approved settlement rate plus two platform
            | constants, so any amount shown to either side is solvable for the
            | other's rate.
            */
            NotificationType::PrivateSessionRequested->value => [
                'طلب حصة خاصة في «{{ course_title }}»',
                'يطلب {{ student_name }} حصة خاصة يوم {{ session_time }} لمدة {{ duration }} دقيقة في «{{ course_title }}». افتح الطلبات للردّ قبل انتهاء المهلة.',
                ['student_name', 'course_title', 'session_time', 'duration'],
            ],
            NotificationType::PrivateSessionAccepted->value => [
                'تأكّدت حصتك الخاصة يوم {{ session_time }}',
                'وافق مدرّسك على حصتك الخاصة في «{{ course_title }}» يوم {{ session_time }} لمدة {{ duration }} دقيقة. تجدها في جدولك.',
                ['course_title', 'session_time', 'duration'],
            ],
            NotificationType::PrivateSessionRejected->value => [
                'لم تُقبل حصتك الخاصة يوم {{ session_time }}',
                'لم يوافق مدرّسك على حصة خاصة يوم {{ session_time }} في «{{ course_title }}». السبب: {{ decision_reason }} — ويمكنك طلب موعد آخر من مواعيده المعلَنة.',
                ['course_title', 'session_time', 'decision_reason'],
            ],
            NotificationType::PrivateSessionExpired->value => [
                'انتهت مهلة طلبك ليوم {{ session_time }}',
                'لم يصل ردّ على طلب حصتك الخاصة يوم {{ session_time }} في «{{ course_title }}»، فانتهت مهلته. لم يُخصم من رصيدك شيء، ويمكنك الطلب من جديد.',
                ['course_title', 'session_time'],
            ],
            NotificationType::SessionRescheduleRequested->value => [
                'طلب تأجيل «{{ title }}»',
                'يطلب {{ student_name }} تأجيل «{{ title }}» من {{ from_time }} إلى {{ to_time }}. السبب: {{ student_reason }} — افتح الطلبات للردّ.',
                ['student_name', 'title', 'from_time', 'to_time', 'student_reason'],
            ],
            NotificationType::SessionRescheduled->value => [
                'تغيّر موعد «{{ title }}»',
                'تغيّر موعد «{{ title }}» من {{ from_time }} إلى {{ to_time }}. الحصة التالية في موعدها المعتاد.',
                ['title', 'from_time', 'to_time'],
            ],
            NotificationType::SessionRescheduleRejected->value => [
                'لم يُقبل تأجيل «{{ title }}»',
                'بقي موعد «{{ title }}» كما هو في {{ from_time }}. السبب: {{ decision_reason }}',
                ['title', 'from_time', 'decision_reason'],
            ],
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
            /*
            | Spec 030. The student reads WHO asked and IN WHAT CAPACITY before
            | deciding; the list of what the guardian would see is on the screen the
            | notification links to, because a message is not the place to render a
            | consent form.
            */
            NotificationType::GuardianLinkRequested->value => [
                'طلب ارتباط من {{ name }}',
                'طلب {{ name }} الارتباط بحسابك بصفة {{ relation_type }}. افتح صفحة «المرتبطون» لقراءة ما سيطّلع عليه قبل أن تقبل أو ترفض.',
                ['name', 'relation_type'],
            ],
            NotificationType::GuardianLinkDecided->value => [
                'ردّ {{ name }} على طلب الارتباط',
                'بتّ {{ name }} في طلب الارتباط الذي أرسلته. افتح صفحة «المرتبطون» لقراءة حالة الطلب.',
                ['name'],
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
                ['teacher_name', 'student_name', 'period_start', 'period_end'],
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
            /*
            | The store (011 · US1).
            |
            | ⚠️ NO AMOUNT AND NO PRICE IN EITHER, for the reason the payment path
            | carries none: a store price is the teacher's shelf price minus a
            | published commission, so a total on a phone is one subtraction away
            | from the teacher's net. And `{{ status }}` is a LABEL from
            | `ShipmentStatus`, never free text — a variable a person can type into
            | is a variable inside an approved template, which is the one thing a
            | provider does not allow.
            |
            | ⚠️ AND THE ORDER OF `variables` IS THE MESSAGE. What travels to the
            | provider is the template NAME and an ORDERED list; a list built by
            | walking a payload puts the status where the title belongs, on a
            | parent's phone, with no error anywhere.
            */
            NotificationType::ShipmentStatusChanged->value => [
                'تحديث شحنة «{{ item_title }}»',
                'شحنة «{{ item_title }}» أصبحت: {{ status }}. تابع التفاصيل من صفحة مشترياتك.',
                ['item_title', 'status'],
            ],
            NotificationType::StorePurchaseUnavailable->value => [
                'طلبك «{{ item_title }}» غير متاح',
                'نفدت النسخ من «{{ item_title }}» قبل اعتماد دفعتك، وطلبك مؤهّل لاسترداد المبلغ. ستصلك رسالة عند إتمامه.',
                ['item_title'],
            ],
            // ⚠️ THE DATE IS IN THE BODY, and «قريباً» is not. A reminder a
            // student cannot act on is a reminder that generates a support
            // question instead of a renewal — and the date it names is
            // `effective_ends_on`, which a freeze may have moved, never the date
            // printed on the plan.
            NotificationType::SubscriptionExpiring->value => [
                'اشتراكك مع {{ teacher_name }} ينتهي قريباً',
                'ينتهي اشتراكك «{{ plan_title }}» مع {{ teacher_name }} بتاريخ {{ ends_on }}. جدّده قبلها لتبقى حصصك ودروسك مفتوحة.',
                ['plan_title', 'teacher_name', 'ends_on'],
            ],
            /*
            | 027 · FR-029 — and the schedule is here in BOTH forms on purpose.
            | The weekly rhythm says «السبت ٥م» and does not say which Saturday;
            | the date of the next lesson says which day and hides the rhythm a
            | guardian organises the week around. FR-029أ is why `next_session`
            | is a SENTENCE rather than an omitted line: a missing row reads as a
            | fault, so when nothing is scheduled yet the message says so.
            */
            NotificationType::SubscriptionActivated->value => [
                'تم تفعيل اشتراكك مع {{ teacher_name }}',
                'اشتراكك «{{ plan_title }}» مع {{ teacher_name }} فعّال من {{ starts_on }} حتى {{ ends_on }}. {{ schedule }} {{ next_session }}',
                ['plan_title', 'teacher_name', 'starts_on', 'ends_on', 'schedule', 'next_session'],
            ],
            /*
            | ٠٣٦ · FR-020 — باقةُ حصصٍ فُعِّلَت.
            |
            | ⛔ **بلا تاريخَين، وهذا سببُ وجودِها منفصلةً.** قالبُ الاشتراكِ
            | فوقَها يطلبُ `starts_on` و`ends_on` ويرمي على أيِّ فراغ، وباقةُ
            | الحصصِ بلا نافذة — فإعادةُ استعمالِه إمّا ترمي بعدَ قبضِ المال
            | وإمّا تخترعُ تاريخاً يقرؤُه الطالبُ حقيقة.
            |
            | ⚠️ و«الرصيد» يُمرَّرُ مصاغاً بـ`CountedNoun`: «حصّتان» لا «٢
            | حصص»، لأنّ العربيّةَ تُوافِقُ المعدودَ في خمسِ نطاقات.
            */
            NotificationType::SessionPlanActivated->value => [
                'تم تفعيل باقة الحصص مع {{ teacher_name }}',
                'باقتك «{{ plan_title }}» مع {{ teacher_name }} فُعِّلت، وأُضيف إلى رصيدك {{ sessions }}. {{ schedule }} {{ next_session }}',
                ['plan_title', 'teacher_name', 'sessions', 'schedule', 'next_session'],
            ],
            /*
            | 027 · FR-042. It names the lesson and the reason, because «تعذّر
            | الحجز» alone sends the student to ask a question the message could
            | have answered. One notice per activation however many sessions it
            | covers — the list is inside `sessions`.
            */
            NotificationType::SubscriptionSeatUnavailable->value => [
                'مقاعد لم تُحجز تلقائيّاً',
                'تعذّر حجز مقعد {{ student_name }} تلقائيّاً في: {{ sessions }}. راجع الجدول لحجز بديل أو لتوسيع السعة.',
                ['student_name', 'sessions'],
            ],
            /*
            | ٠٣٤ · FR-019 — باقةٌ أنشأَتها الإدارةُ باسمِ المدرّس.
            |
            | ⚠️ **السعرُ في المتن.** الباقةُ تُباعُ باسمِ المدرّسِ بسعرٍ لم يضعْه،
            | ورسالةٌ تقولُ «أُنشِئَت باقة» بلا رقمٍ تتركُ الشيءَ الوحيدَ الذي
            | يحتاجُ المدرّسُ أن يعترضَ عليه خارجَ الرسالة.
            */
            /*
            | ⛔ ٠٣٦ · FR-013 — `{{ hidden_cohorts }}`, وبدونِه لا يعرفُ
            | المدرّسُ أبداً أنّ مجموعةً من مجموعاتِه خرجَت من العرض.
            | الموظّفُ يُحذَّرُ ويُعلِّمُ المربّعَ ويمضي — وقبلَ هذا كانَ يقرأُ
            | صاحبُ المجموعةِ «وافقت الإدارة» ولا شيءَ أكثر، ثمّ يعرفُ
            | حينَ يسألُه طالبٌ «ليه مجموعتنا مش ظاهرة؟».
            |
            | ⚠️ **والمتغيّرُ لا يكونُ فارغاً أبداً**: `TemplateRenderer`
            | يعدُّ المتغيّرَ الفارغَ غائباً ويرمي فشلَ تسليمٍ دائماً،
            | فموافقةٌ لم تُخفِ شيئاً تُسقِطُ الرسالةَ كلَّها. والجملةُ
            | الهادئةُ تُقالُ عمداً — سطرٌ لا يظهرُ إلّا عندَ العطبِ سطرٌ
            | لا يعرفُ أحدٌ أن يبحثَ عنه.
            */
            NotificationType::PlanChangeApproved->value => [
                'قُبل تعديل باقة «{{ plan_title }}»',
                'وافقت الإدارة على تعديل باقة «{{ plan_title }}». الباقة الجديدة تبيع {{ shape }}، '
                .'والقديمة أُوقفت عن البيع ويبقى اشتراك من اشترك بها كما هو. {{ hidden_cohorts }} {{ reason }}',
                ['plan_title', 'shape', 'hidden_cohorts', 'reason'],
            ],
            NotificationType::PlanChangeRejected->value => [
                'لم يُقبل تعديل باقة «{{ plan_title }}»',
                'لم توافق الإدارة على تعديل باقة «{{ plan_title }}» إلى {{ shape }}، والباقة كما هي. {{ reason }}',
                ['plan_title', 'shape', 'reason'],
            ],
            NotificationType::PlanCreatedForYou->value => [
                'أُنشئت باقة باسمك: «{{ plan_title }}»',
                /*
                | ⛔ ٠٣٦ — «{{ shape }}» بدلَ «{{ duration_days }} يوماً»، وبدونِ
                | هذا التغييرِ تسقطُ الرسالةُ كلَّها في صمت. `TemplateRenderer`
                | يرفضُ متغيّراً فارغاً، وباقةُ الحصصِ لا تحملُ مدّةً إطلاقاً —
                | فالمدرّسُ الذي أُنشئَت باسمِه باقةُ اثنتَي عشرةَ حصّةً لا يعلمُ
                | بها، والصفُّ مكتوبٌ والمالُ مُسعَّرٌ ولا سطرَ في السجلّ.
                */
                'أنشأت إدارة المنصّة باقة «{{ plan_title }}» باسمك بسعر {{ price }}، وتبيع {{ shape }}. راجعها في باقاتك، وتواصل مع الإدارة إن كان فيها ما يحتاج تعديلاً.',
                ['plan_title', 'price', 'shape'],
            ],
            /*
            | ⚠️ THE NUMBERS ARE IN THE BODY, NOT A LINK TO THEM. A report that
            | says «تقريرك جاهز» is a notification whose whole content is a second
            | trip to the panel — and the person reading it on a phone at night is
            | exactly who wanted the number rather than the screen.
            */
            NotificationType::ScheduledReport->value => [
                'تقرير المنصّة — {{ period }}',
                'أرقام المنصّة حتى {{ date }}: {{ summary }}',
                ['period', 'date', 'summary'],
            ],
            /*
            | Spec 032 · FR-020 — a viewer reported that a hosted video is broken.
            |
            | ⚠️ IT NAMES THE COURSE AND THE LESSON AND NOTHING ELSE. The report
            | carries no body at all — a free-text field written by an anonymous
            | stranger, arriving at a teacher's bell, is an unmoderated message
            | channel — so there is nothing here for one to be interpolated into.
            |
            | ⚠️ AND IT DOES NOT PROMISE THE VIDEO IS GONE. Nobody knows that: the
            | platform cannot see inside another origin's frame, and the reporter
            | may be wrong. The wording says what actually happened — somebody
            | said it did not work.
            */
            NotificationType::LessonLinkReported->value => [
                'بلاغ: «{{ lesson_title }}» لا يعمل',
                'أبلغنا مشاهدٌ أنّ فيديو «{{ lesson_title }}» في «{{ course_title }}» لا يعمل. الفيديو مستضاف على قناتك، فافتح الدرس وتأكّد من الرابط.',
                ['lesson_title', 'course_title'],
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
                /*
                 * ⚠️ «وأُضيف رصيدك» WAS TRUE OF ONE KIND OF ORDER. The listener
                 * sends this for a credit purchase AND a store purchase — the two
                 * kinds whose manual approval nothing else announces — and a store
                 * buyer holds no balance. Reworded by
                 * `2026_09_23_000600_reword_receipt_approved_for_every_kind`.
                 */
                'راجع الفريق إيصالك عن «{{ course }}» واعتمده، وبدأ تنفيذ طلبك.',
                ['course'],
            ],
            /*
             * The officer's half (the receipt's first moment). No amount, for the
             * reason the rest of this block has none, and because the amount is
             * one click away on the screen where the decision is taken.
             */
            NotificationType::ReceiptAwaitingReview->value => [
                'إيصال جديد بانتظار المراجعة',
                'رفع {{ payer }} إيصال تحويل عن «{{ order }}». راجعه واعتمده أو ارفضه من شاشة الطلبات.',
                ['payer', 'order'],
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
                ['name', 'certificate_number', 'course_title'],
            ],
            NotificationType::CertificateRegenerated->value => [
                'أُعيد إصدار شهادتك',
                'حُدِّثت شهادتك رقم {{ certificate_number }}. رمز التحقّق منها وتاريخ منحها لم يتغيّرا، والرابط الذي شاركته يعمل كما كان.',
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
            // The guardian-facing types below were declared in 003 ahead of
            // their producers. `payment_reminder` never got one and was deleted
            // (2026-09-24); the others each have one now, and
            // `EveryNotificationTypeIsTestedTest` fails the build over a type
            // that has none.
            NotificationType::AttendanceAlert->value => [
                'تنبيه حضور',
                'لم يحضر {{ student_name }} حصّة {{ session_title }} بتاريخ {{ session_date }}.',
                ['student_name', 'session_title', 'session_date'],
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
