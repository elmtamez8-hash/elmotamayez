<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compliance\Enums\ErasureCapability;
use App\Modules\Compliance\Models\DataProcessor;
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
    public function run(): void
    {
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
                'purpose_ar' => 'يُشغّل غرفة البثّ الحيّ، فيمرّ به صوتُ الحصة وصورتُها أثناءها ويُجمَع منه ملفُّ التسجيل.',
                'processing_location' => 'خوادم المزوّد خارج قطر',
                'categories' => ['class_recording', 'student_name'],
                // The room is torn down when the session closes and the recording
                // is handed to us; there is no standing store to ask about.
                'erasure_capability' => ErasureCapability::Full->value,
                'is_active' => true,
            ],
            [
                'key' => 'bunny',
                'name' => 'Bunny Stream',
                'purpose_ar' => 'يخزّن تسجيلات الحصص ويوزّعها على من يحقّ له مشاهدتها.',
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
                'purpose_ar' => 'يخزّن الملفّات المرفوعة — إيصالات التحويل وملفّات الدروس وأرشيف التصدير.',
                'processing_location' => 'خوادم المزوّد خارج قطر',
                'categories' => ['payment_record', 'class_recording'],
                'erasure_capability' => ErasureCapability::Full->value,
                'is_active' => true,
            ],
            [
                'key' => 'whatsapp',
                'name' => 'WhatsApp Business',
                'purpose_ar' => 'يوصل الرسائل إلى هاتفك أو هاتف وليّ أمرك — تقارير الحصص وتنبيهات الحضور.',
                'processing_location' => 'خوادم المزوّد خارج قطر',
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
                'key' => 'meilisearch',
                'name' => 'Meilisearch',
                'purpose_ar' => 'يفهرس الكورسات والأسئلة ليعمل البحث.',
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
                'purpose_ar' => 'يحمل الطوابير والذاكرة المؤقّتة، فتمرّ به حمولاتُ الوظائف لحظياً.',
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
                'purpose_ar' => 'يُبلَّغُ محرّكات البحث بعناوين المقالات المنشورة لتزورَها وتفهرسَها.',
                'processing_location' => 'خوادم المزوّد خارج قطر',
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
        ];
    }
}
