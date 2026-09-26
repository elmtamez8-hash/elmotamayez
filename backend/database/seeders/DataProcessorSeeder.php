<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compliance\Enums\ErasureCapability;
use App\Modules\Compliance\Models\DataProcessor;
use App\Shared\Database\TranslatableColumns;
use Illuminate\Database\Seeder;

/**
 * Who receives personal data outside our own servers (FR-024 · SC-011).
 *
 * ⚠️ TWO OF THESE DID NOT EXIST WHEN THE SPEC WAS WRITTEN, and they are the two
 * that carry a child's voice and face. The broadcast provider was
 * `NullBroadcastProvider` and media was our own disk; 017 and 019 changed both,
 * and a register written from the spec alone would have omitted exactly the
 * processors a parent would ask about first.
 *
 * ⚠️ AND `erasure_capability` DIFFERS PER ROW ON PURPOSE. Declaring `full`
 * everywhere would be a promise to the subject that at least one of them cannot
 * keep — an edge cache expires on its own schedule and accepts no instruction.
 */
class DataProcessorSeeder extends Seeder
{
    /*
    | ⚠️ «خارج قطر» كانَ صحيحاً عن مكانِ المزوّدِ وخاطئاً عن القارئ: المنصّةُ
    | تخدمُ مصرَ والخليجَ معاً (٢٠٢٦-٠٩-٢٦)، فالجملةُ تُقاسُ من بلدِ مَن يقرؤُها
    | لا من بلدٍ واحد. ولا نسمّي مركزَ بياناتٍ لم نتحقّقْ منه.
    |
    | ⚠️ ثوابتُ لا نصوصٌ مكرّرة، لأنّ هجرةَ ٢٠٢٦_٠٩_٢٦ تكتبُها على قاعدةٍ قائمةٍ —
    | و`firstOrCreate` هنا لا يُعيدُ صياغةَ صفٍّ موجود.
    */
    public const VENDOR_ABROAD = 'خوادم المزوّد، وقد تكون خارج بلدك';

    public const BROWSER_VENDOR_ABROAD = 'خوادم مزوّد المتصفّح، وقد تكون خارج بلدك';

    /*
    | ⚠️ الاسمُ يسمّي الوسيطَ لا المنصّةَ وحدَها: رسائلُنا تمرُّ على 360dialog
    | (`WHATSAPP_BASE_URL` — `waba-v2.360dialog.io`) قبلَ أن تصلَ إلى Meta،
    | وسجلٌّ يقولُ «WhatsApp Business» فقط يُخفي طرفاً يرى كلَّ رقمٍ نُراسِلُه.
    */
    public const WHATSAPP_NAME = 'WhatsApp Business عبر 360dialog';

    public function run(): void
    {
        // The conversion migration has not run yet — see {@see TranslatableColumns::converted}.
        if (! TranslatableColumns::converted('data_processors', 'purpose')) {
            return;
        }

        foreach ($this->processors() as $processor) {
            // On the key alone — reference data at birth, operator data after.
            DataProcessor::query()->firstOrCreate(['key' => $processor['key']], $processor);
        }
    }

    /** @return list<array<string, mixed>> */
    private function processors(): array
    {
        return [
            [
                'key' => 'livekit',
                'name' => 'LiveKit',
                'purpose' => 'يُشغّل غرفة البثّ الحيّ، فيمرّ به صوتُ الحصة وصورتُها أثناءها ويُجمَع منه ملفُّ التسجيل.',
                'processing_location' => self::VENDOR_ABROAD,
                'categories' => ['class_recording', 'student_name'],
                // The room is torn down when the session closes and the recording
                // is handed to us; there is no standing store to ask about.
                'erasure_capability' => ErasureCapability::Full->value,
                'is_active' => true,
            ],
            [
                'key' => 'bunny',
                'name' => 'Bunny Stream',
                'purpose' => 'يخزّن تسجيلات الحصص ويوزّعها على من يحقّ له مشاهدتها.',
                'processing_location' => 'شبكة توزيعٍ عالمية',
                'categories' => ['class_recording'],
                /*
                | ⚠️ `partial`, AND THE HONESTY MATTERS. The stored video is
                | deleted on request; the copies its own edge network already
                | distributed lapse on their cache schedule and cannot be recalled.
                | Saying `full` here would put a sentence in an erasure report that
                | is not true.
                */
                'erasure_capability' => ErasureCapability::Partial->value,
                'is_active' => true,
            ],
            [
                'key' => 'r2',
                'name' => 'Cloudflare R2',
                'purpose' => 'يخزّن الملفّات المرفوعة — إيصالات التحويل وملفّات الدروس وأرشيف التصدير.',
                'processing_location' => self::VENDOR_ABROAD,
                'categories' => ['payment_record', 'class_recording'],
                'erasure_capability' => ErasureCapability::Full->value,
                'is_active' => true,
            ],
            [
                'key' => 'whatsapp',
                'name' => self::WHATSAPP_NAME,
                'purpose' => 'يوصل الرسائل إلى هاتفك أو هاتف وليّ أمرك — تقارير الحصص وتنبيهات الحضور.',
                'processing_location' => self::VENDOR_ABROAD,
                'categories' => ['contact_phone', 'student_name', 'notification_record'],
                /*
                | ⚠️ `none`, and it is the row a reader should look at twice. A
                | message that has been delivered to a phone cannot be unsent, and
                | the provider keeps its own delivery log under its own policy. The
                | register says so rather than implying we control it.
                */
                'erasure_capability' => ErasureCapability::None->value,
                'is_active' => true,
            ],
            [
                /*
                | Spec 012 · US2. ⚠️ NOT ONE VENDOR BUT THREE, AND THE ROW SAYS SO:
                | the endpoint the browser hands us decides who receives the
                | message — Google for Chrome, Mozilla for Firefox, Apple for
                | Safari — and the account holder's browser makes that choice, not
                | us. A register naming only Google would describe a third of the
                | users and hide the rest.
                |
                | ⚠️ AND THE ROW IS NOT CEREMONY. `ProcessorAllowlistTest` derives
                | its list from the `notification.channels` tag and fails the build
                | for any external channel with no entry — which is what stops a
                | channel shipping before somebody has written down what it sends
                | and where. What travels here is a per-user device identifier plus
                | a short title and a link; the message body deliberately stays on
                | our side.
                */
                'key' => 'push',
                'name' => 'خدمات الدفع في المتصفّحات (Google · Mozilla · Apple)',
                'purpose' => 'توقظ هاتفك بإشعار الحصّة أو الرصيد أو الحساب — عنوانٌ قصيرٌ ورابط، لا نصّ الرسالة.',
                'processing_location' => self::BROWSER_VENDOR_ABROAD,
                'categories' => ['notification_record', 'push_subscription'],
                /*
                | ⚠️ `partial`, AND THE HONESTY IS THE SAME AS BUNNY'S. Deleting the
                | subscription here stops every future message and is instant; a
                | notification already delivered to a device cannot be recalled, and
                | the push service keeps its own delivery log under its own policy.
                */
                'erasure_capability' => ErasureCapability::Partial->value,
                'is_active' => true,
            ],
            [
                'key' => 'meilisearch',
                'name' => 'Meilisearch',
                'purpose' => 'يفهرس الكورسات والأسئلة ليعمل البحث.',
                'processing_location' => 'خادمُنا',
                'categories' => ['authored_content'],
                /*
                | ⚠️ THE INDEX IS THE DERIVED COPY EVERYONE FORGETS. `Model::search()`
                | queries the engine OUTSIDE every global scope, so a row deleted
                | from MySQL still comes back in a search result — `FR-023`
                | literally. `unsearchable()` is what closes it, and `SCOUT_DRIVER=null`
                | in tests means no local run ever sees the gap.
                */
                'erasure_capability' => ErasureCapability::Full->value,
                'is_active' => true,
            ],
            [
                'key' => 'redis',
                'name' => 'Redis',
                'purpose' => 'يحمل الطوابير والذاكرة المؤقّتة، فتمرّ به حمولاتُ الوظائف لحظياً.',
                'processing_location' => 'خادمُنا',
                'categories' => ['notification_record'],
                /*
                | ⚠️ LISTED BECAUSE OF `failed_jobs`, not because of the cache. A job
                | payload serialises its constructor arguments — so an Action that
                | passed an array of a child's records to a job would leave it in
                | Redis, and on failure in `failed_jobs.payload`, a table nothing
                | sweeps. That is why every job in this phase takes an ID and
                | re-reads.
                */
                'erasure_capability' => ErasureCapability::Partial->value,
                'is_active' => true,
            ],
            [
                'key' => 'indexnow',
                'name' => 'IndexNow',
                'purpose' => 'يُبلَّغُ محرّكات البحث بعناوين المقالات المنشورة لتزورَها وتفهرسَها.',
                'processing_location' => self::VENDOR_ABROAD,
                /*
                | ⚠️ WHAT TRAVELS IS A URL, AND WHAT THAT URL LEADS TO IS THE
                | REASON THE ROW EXISTS. The submission itself carries a host, a
                | key and a list of addresses — no name, no contact, nothing about
                | a student. But it is an invitation to fetch a page that carries
                | the teacher's name and photo, and a register that listed only
                | the bytes we post would describe the mechanism and hide the
                | consequence.
                */
                'categories' => ['authored_content'],
                /*
                | ⚠️ `none`, and it is the honest answer. There is no «forget
                | this» in the protocol; a page that is taken down is dropped when
                | the crawler next finds a 404, on the engine's own schedule and
                | under its own policy. Claiming `full` would put a sentence in an
                | erasure report that we cannot keep.
                */
                'erasure_capability' => ErasureCapability::None->value,
                'is_active' => true,
            ],
            [
                /*
                | ⚠️ يعملُ على الإنتاجِ منذ ٢٠٢٦-٠٩-٢٤ ولم يكنْ له صفّ. البريدُ لا
                | يمرُّ بـ`DispatchNotification` (قناةُ البريدِ هناك «غيرُ منفَّذة»)،
                | فـ`ProcessorAllowlistTest` المشتقُّ من الوسمِ لا يراه — رابطُ
                | استعادةِ كلمةِ المرورِ (`AuthController` · `Password::sendResetLink`)
                | وتنبيهُ المشغّلِ يخرجانِ عبرَ SMTP إلى Brevo مباشرةً. فالصفُّ هنا لأنّ لا شيءَ في الحزمةِ كانَ سيطلبُه.
                */
                'key' => 'brevo',
                'name' => 'Brevo',
                'purpose' => 'يوصل رسائل البريد الإلكتروني من المنصّة إليك — اليومَ رابطُ استعادة كلمة المرور وحده.',
                'processing_location' => self::VENDOR_ABROAD,
                'categories' => ['account_email'],
                /*
                | ⚠️ `partial` بالأمانةِ نفسِها التي في `push`: رسالةٌ وصلَت صندوقاً
                | لا تُستردّ، والمزوّدُ يحفظُ سجلَّ إرسالِه بسياستِه هو.
                */
                'erasure_capability' => ErasureCapability::Partial->value,
                'is_active' => true,
            ],
            [
                /*
                | ⚠️ الصفُّ الذي تحتَ كلِّ «خادمُنا» في هذا السجلّ. Redis وMeilisearch
                | وقاعدةُ البياناتِ والمرفوعاتُ المحلّيّةُ كلُّها تعملُ على خادمٍ
                | افتراضيٍّ مستأجَرٍ من Hostinger، فالمستضيفُ يحملُ كلَّ ما نحفظُه.
                | والفئاتُ المذكورةُ هي الأوضحُ لقارئ، لا حصرٌ — والغرضُ يقولُ «كلّ».
                |
                | ⚠️ `partial` لأنّ `scripts/backup.sh` يحفظُ نسخةً يوميّةً على الخادمِ
                | نفسِه ١٤ يوماً (`BACKUP_KEEP_DAYS`): ما يُحذَفُ اليومَ يبقى في تلك
                | النسخِ حتى تنقضيَ مدّتُها. قولُ `full` هنا جملةٌ كاذبةٌ في تقريرِ حذف.
                */
                'key' => 'hostinger',
                'name' => 'Hostinger',
                'purpose' => 'يستضيف الخادم الذي تعمل عليه المنصّة، فتُحفظ عليه كلّ بياناتنا ونسخُها الاحتياطية اليومية.',
                'processing_location' => 'مركز بيانات المزوّد الذي يستضيف خادمنا',
                'categories' => [
                    'student_name',
                    'account_email',
                    'contact_phone',
                    'date_of_birth',
                    'enrollment_record',
                    'exam_attempt',
                    'attendance_record',
                    'payment_record',
                    'chat_message',
                    'auth_session',
                ],
                'erasure_capability' => ErasureCapability::Partial->value,
                'is_active' => true,
            ],
        ];
    }
}
