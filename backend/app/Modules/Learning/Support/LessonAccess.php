<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Courses\Support\LessonAudience;

/**
 * Whether the student may open this item, and — when not — why (FR-043).
 *
 * A bare `false` was enough while the only reason was "finish the previous
 * lesson", because the student could see that lesson right above the locked one
 * and work it out. An exam gate is not visible that way: the student sees a
 * closed item and has no way to learn that a quiz two positions back has to be
 * PASSED rather than merely sat, or that the attempt they abandoned halfway
 * does not count. A lock with no explanation is a support ticket.
 *
 * The reason travels as a `code` beside the sentence. The sentence is what the
 * student reads; the code is what a test asserts on and what a screen switches
 * on — asserting on Arabic prose means the message can never be reworded.
 */
final class LessonAccess
{
    public const SEQUENCE = 'sequence';

    public const EXAM_ATTEMPT = 'exam_attempt';

    public const EXAM_PASS = 'exam_pass';

    public const NOT_ENROLLED = 'not_enrolled';

    /**
     * The item, or a parent of it, is a draft or archived.
     *
     * Worded to the student as "not available", with no hint of what is behind it:
     * that a teacher has an unfinished lesson at this position is the teacher's
     * business, and "coming soon" invites the student to keep trying the URL.
     */
    public const NOT_VISIBLE = 'not_visible';

    public const INACTIVE = 'inactive';

    /**
     * A session recording, opened by someone who held no seat in that session.
     *
     * The third entitlement route (FR-030): enrolment in the course is not
     * enough, because the hour was sold by the seat. Distinct from SEQUENCE
     * because there is nothing to go and finish — the answer is not "later".
     */
    public const NO_SEAT = 'no_seat';

    /**
     * The course runs in groups and the student is in none of them (FR-028أ).
     *
     * ⚠️ IT IS ONLY EVER RAISED WHILE THERE IS A GROUP THEY COULD JOIN. The
     * moment the last joinable one fills, closes or is archived, this reason
     * disappears and the whole curriculum opens — see `CohortGate`. A condition
     * no action of the student's can satisfy is a permanent lock on content they
     * have already paid for.
     *
     * Distinct from SEQUENCE because what has to happen is not finishing
     * anything, and distinct from NO_SEAT because there IS something they can
     * do: NO_SEAT means "not yours", this means "pick a group first".
     */
    /**
     * ٠٣٥ — الحصّةُ لمجموعةٍ أخرى: مقفولةٌ، ولا تُشترى.
     *
     * ⛔ A CODE OF ITS OWN RATHER THAN A SECOND `NO_SEAT` MESSAGE, and the
     * reason is a number on the screen: `CurriculumResource::lockedSessionCount()`
     * counts `NO_SEAT` rows and the page prints «كذا حصّةً مقفولةً — افتحْها
     * بكذا من رصيدِك». Folded in here, that offers to sell hours the endpoint
     * refuses — a price quoted for something not on sale.
     *
     * It names no action because the student has none: the group is the
     * teacher's decision, and after ٠٣٤ it is the administration's.
     */
    public const OTHER_COHORT = 'other_cohort';

    /**
     * ٠٢٦ — حصّةٌ انتهت ولم تُسلَّمْ قطّ: لا محتوى لها يُفتَحُ ولا يُشترى.
     *
     * ⛔ AND THE ROW IS REMOVED, NOT WORDED — the one code here that
     * `CurriculumResource` drops rather than renders. There is no action behind
     * it in either direction: the hour was never given, so nothing is owed and
     * nothing is on sale, and «مقفول» with no way out is the «رقمٌ ناقصٌ بلا
     * سبب» that ٠٣٥ · FR-025 exists to forbid.
     *
     * ⚠️ IT IS NOT A THEORETICAL STATE. The recording ingest hangs off
     * `SessionCompleted`, not `SessionDelivered`, so a session whose teacher
     * never turned up still produces a lesson in the tree — and until today its
     * row promised «افتحه بخصم حصة من رصيدك» while the unlock endpoint refused
     * it one press later, because `unlockableSessionIds()` never asked about
     * delivery and `unlockOfferFor()` always did.
     */
    public const NO_SESSION_CONTENT = 'no_session_content';

    /*
    | ٠٢٦ — الرمزانِ الآتيانِ **مُسنَدانِ من {@see LessonAudience} لا مكتوبانِ
    | ثانية**، وهو الصنفُ الذي يُصدِرُهما. حرفيّةٌ مكرَّرةٌ هنا هي تهجئةٌ ثانيةٌ
    | لقيمةٍ واحدةٍ تفترقانِ يومَ يتغيّرُ أحدُهما، وسُمِّيَتِ الجهةُ هكذا لأنّ
    | `Learning` تستوردُ `Courses` أصلاً — والعكسُ حدٌّ جديدٌ لا داعيَ له.
    |
    | ⛔ **وكلاهما يُسقِطُ الصفَّ ولا يُوصَفُ**، كما يفعلُ `NO_SESSION_CONTENT`
    | فوقَه: لا فعلَ للطالبِ في أيٍّ منهما، و«مقفولٌ» بلا مخرجٍ هو «الرقمُ
    | الناقصُ بلا سبب» الذي تمنعُه ٠٣٥ · FR-025.
    */

    /** العنصرُ مقصورٌ على مجموعةٍ ليسَ القارئُ فيها. */
    public const OUT_OF_SCOPE = LessonAudience::OUT_OF_SCOPE;

    /** العنصرُ ينتظرُ حصّةً لم تُعقَدْ بعدُ ولم تُلغَ. */
    public const UNRELEASED = LessonAudience::UNRELEASED;

    /**
     * رموزُ «يُسقَطُ الصفُّ ولا يُوصَف» — تهجئةٌ واحدةٌ لقارئَين.
     *
     * ⛔ **والقارئانِ بابانِ لا شاشةٌ واحدة.** `CurriculumResource` يُسقِطُ
     * الصفَّ من القائمة، و`lessonPayload()` يجيبُ ٤٠٤ لمن طرَقَ العنوانَ
     * مباشرةً — وحُجّتُهما واحدةٌ مكتوبةٌ في `NOT_VISIBLE` منذُ زمن: **«أنَّ درساً
     * بعينِه موجودٌ عندَ هذا المعرّفِ خبرٌ في نفسِه»**. فصفٌّ مُسقَطٌ من المنهجِ
     * تُرسَلُ عنوانُه من البابِ الآخرِ هو إخفاءٌ على شاشةٍ واحدةٍ وحسب.
     *
     * @var list<string>
     */
    public const HIDDEN_CODES = [
        self::NOT_VISIBLE,
        self::NO_SESSION_CONTENT,
        self::OUT_OF_SCOPE,
        self::UNRELEASED,
    ];

    public static function hidesRow(?string $code): bool
    {
        return $code !== null && in_array($code, self::HIDDEN_CODES, true);
    }

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        /** What the student has to go and do — the item that unlocks this one. */
        public readonly ?string $blockedByTitle = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $code, string $message, ?string $blockedByTitle = null): self
    {
        return new self(false, $code, $message, $blockedByTitle);
    }
}
