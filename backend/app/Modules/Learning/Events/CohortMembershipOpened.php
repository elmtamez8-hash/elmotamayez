<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\CohortMembershipEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student entered a group of a course — moved into it, or joined their first.
 *
 * ⚠️ IT CARRIES THE OLD GROUP'S ID BECAUSE BY THE TIME A LISTENER RUNS, NOTHING
 * ELSE DOES. The membership row is already closed and the student already
 * belongs somewhere else, so a listener that went looking for "the group they
 * left" would find the one they just joined and release the seats they are
 * entitled to keep. Same reasoning as `SessionCancelled` carrying its seat
 * holders.
 *
 * ⚠️ IT USED TO BE WITHHELD ON A FIRST JOIN, AND SPEC 052 REPEALED THAT REASON.
 * The old rule was sound while the only listener RELEASED seats: a student with
 * no previous membership has none to give up, and an event whose every listener
 * returns immediately is a listener list nobody prunes. Seating a member is the
 * mirror half of that listener, and it is the FIRST join that most needs it —
 * the ordinary case is a teacher generating a term on Sunday and students
 * joining through the week, which under the old rule seated nobody at all.
 * `fromCohortId` is nullable for exactly that: null means «came from nowhere»,
 * and the release arm is skipped on it while the booking arm always runs.
 * (FR-023: a comment citing a repealed reason must say that it was repealed.)
 *
 * Carries integers rather than models: it crosses into LiveSessions, which owns
 * the seat and must not be handed a `Cohort` (Constitution III).
 */
class CohortMembershipOpened
{
    use Dispatchable;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $courseId,
        public readonly int $studentUserId,
        public readonly ?int $fromCohortId,
        public readonly int $toCohortId,
        /**
         * ⚠️ **أيُّ فعلٍ فتحَ هذه العضويّة** — قيمةٌ من ثوابتِ
         * {@see CohortMembershipEvent}.
         *
         * أُضيفَ في ٠٣٤ · FR-006 لأنّ هذا الحدثَ **يقعُ على أربعةِ مساراتٍ**
         * وإشعارُ الإسنادِ يخصُّ واحداً منها: الطالبُ الذي انضمَّ بنفسِه يرى
         * النتيجةَ على الشاشةِ التي ضغطَ فيها، وانتقالٌ وُوفِقَ عليه **له
         * إشعارُه** (`CohortTransferApproved`) — فمستمِعٌ بلا هذا الحقلِ يُرسِلُ
         * رسالتَينِ عن حركةٍ واحدة.
         *
         * ولا يُشتَقُّ من `fromCohortId`: `null` تعني «جاءَ من لا مكان»، وهي
         * صادقةٌ على الإسنادِ وعلى انضمامِ الطالبِ بنفسِه معاً.
         *
         * ⚠️ **ومطلوبٌ بلا قيمةٍ افتراضيّة، عمداً.** افتراضُ `''` يجعلُ «نسيَ
         * المُنشِئُ تمريرَه» **صمتاً لا خطأً**: لا إشعارَ، ولا سطرَ سجلّ، ولا
         * شيءَ في أيِّ مِلَفّ — وهو شكلُ `ClassSessionStatus::Interrupted` نفسُه
         * الذي أُصلِحَ في `T006` بعدَ أن عاشَ طوراً كاملاً يبدو منفَّذاً.
         */
        public readonly string $membershipEvent,
    ) {}
}
