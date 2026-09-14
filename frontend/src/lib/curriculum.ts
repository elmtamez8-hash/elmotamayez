import { api } from "./api";

/**
 * The course page's payload: the whole tree, with a state on every row and a
 * reason on every closed one.
 *
 * Mirrors `CurriculumResource` field for field. Read the PHP resource before
 * changing one — a type that claims a field the API does not send renders a
 * blank with no error anywhere, which is how six screens across four modules
 * shipped listing every student as «».
 */

/** ⚠️ The constants of `LessonAccess`, plus the one 021 adds. */
export type LockCode =
  | "sequence"
  | "exam_attempt"
  | "exam_pass"
  | "no_seat"
  | "other_cohort"
  | "inactive"
  | "no_cohort";

export type LessonState = "completed" | "open" | "locked";

export interface CurriculumLesson {
  uuid: string;
  title: string;
  type: string;
  type_label: string;
  /** inline · uploaded · reference · external */
  family: string;
  asset_kind: string | null;
  is_completable: boolean;
  duration_seconds: number;
  state: LessonState;
  /** Null on anything the student may open — including a finished item. */
  lock: {
    code: LockCode;
    message: string;
    /** What to go and do. Null when there is no single item to name. */
    blocked_by_title: string | null;
  } | null;
}

export interface CurriculumChapter {
  uuid: string;
  title: string;
  order: number;
  lessons: CurriculumLesson[];
}

export interface CurriculumSection {
  uuid: string;
  title: string;
  order: number;
  chapters: CurriculumChapter[];
}

export interface Curriculum {
  course: {
    uuid: string;
    /**
     * ما يُنادَى به `/enrollments/{enrollment}/reset` — والصفحةُ تحملُ معرّفَ
     * الكورسِ في مسارِها لا معرّفَ التسجيل.
     */
    enrollment_uuid: string;
    title: string;
    cover_url: string | null;
    teacher_name: string | null;
    is_sequential: boolean;
    course_type: string;
    progress_pct: number;
    completed_count: number;
    countable_count: number;
    /** Null on a finished course, and on one whose first item is shut. */
    resume_lesson_uuid: string | null;
    /**
     * ٠٣٥ · FR-025 — كم حصّةً من هذا الكورسِ مقفولةٌ لانتظارِ موافقةٍ بخصمِ رصيد.
     *
     * ⛔ **حِصَصٌ لا عناصر.** موافقةٌ واحدةٌ تفتحُ الساعةَ كلَّها بخصمِ حصّةٍ
     * واحدة، فـ«سبعةُ عناصرَ مقفولة» تُسعِّرُ الساعةَ بسبعِ حصصٍ في رأسِ القارئ.
     *
     * ⚠️ **والرقمُ من الخادمِ ولا يُعَدُّ هنا.** عَدُّ صفوفِ `no_seat` في
     * TypeScript هجاءٌ ثانٍ لسؤالٍ يُجيبُه الخادمُ فوقَ خريطةِ الوصولِ نفسِها،
     * وهذه الشاشةُ بُنيَت أصلاً لإنهاءِ «جوابٌ هنا وآخرُ هناك».
     */
    locked_session_count: number;
  };
  /**
   * ⚠️ `required: false` FOR EVERY COURSE UNTIL GROUPS EXIST (US3). Not a
   * placeholder that lies: no course requires a group today, so no student is
   * missing one. `joinable_exists` is the valve — a course that requires a group
   * while none is joinable must open completely, or a condition no action can
   * satisfy becomes a permanent lock on content somebody paid for.
   */
  cohort_gate: {
    required: boolean;
    satisfied: boolean;
    joinable_exists: boolean;
    message: string | null;
  };
  sections: CurriculumSection[];
}

/**
 * A last-resort sentence per refusal.
 *
 * ⚠️ THE SERVER'S MESSAGE IS THE ONE TO SHOW, ALWAYS — it names the exact item
 * to go and finish, which is the whole of FR-043 and which no client-side map
 * can reproduce. This exists for a payload that somehow arrives without one, so
 * the row never renders a bare «مقفول»: a lock with nothing after it is a
 * support ticket.
 */
const FALLBACK: Record<LockCode, string> = {
  sequence: "أكمِل الدرس السابق أولاً — هذا الكورس متسلسل.",
  exam_attempt: "أدِّ الاختبار السابق وسلّم إجابتك ليُفتح ما بعده.",
  exam_pass: "لا يُفتح ما بعد الاختبار السابق حتى تجتازه بالدرجة المطلوبة.",
  no_seat: "هذا تسجيل حصة لم تحجز فيها مقعداً.",
  other_cohort: "هذه الحصة ليست من حصص مجموعتك.",
  inactive: "تسجيلك في هذا الكورس غير نشط حالياً.",
  no_cohort: "اختر مجموعتك للبدء.",
};

export function lockMessage(lock: CurriculumLesson["lock"]): string {
  if (lock === null) return "";

  return lock.message || FALLBACK[lock.code] || "هذا الدرس غير متاح لك الآن.";
}

export function curriculum(courseUuid: string): Promise<Curriculum> {
  return api.get<Curriculum>(`/courses/${courseUuid}/curriculum`);
}

/**
 * الشجرةُ مفرودةً في ترتيبِها.
 *
 * ⚠️ **قراءةُ ترتيبٍ لا اشتقاقُه.** الخادمُ يبني القائمةَ بـ
 * `(section.order, chapter.order, lesson.order)` ثمّ يُعشِّشُها، فالمشيُ عليها
 * بالترتيبِ نفسِه يُعيدُ الترتيبَ الأصليَّ بلا فرز. وأيُّ `sort` هنا هجاءٌ ثانٍ
 * لترتيبٍ يُقرَّرُ في الخادم — وترتيبُ الشجرةِ في هذا المنتَجِ **كتابةٌ في حقوقِ
 * الوصولِ** لا ترتيبُ عرض، فنسخةٌ ثانيةٌ منه تفتحُ لطالبٍ ما لم يُفتَحْ له.
 *
 * ⚠️ **والمقفولُ يبقى في القائمة.** حذفُه هنا يجعلُ «التالي» يقفزُ فوقَ الدرسِ
 * الذي يجبُ إكمالُه — أي زرٌّ يتخطّى القفلَ الذي وُجِدَ ليمنعَه. الصفُّ المحذوفُ
 * أصلاً (`NOT_VISIBLE`) لا يصلُ الحمولةَ من الخادمِ إطلاقاً.
 */
export function flattenLessons(tree: Curriculum): CurriculumLesson[] {
  return tree.sections.flatMap((section) =>
    section.chapters.flatMap((chapter) => chapter.lessons),
  );
}

/** موضعُ درسٍ في الكورس، وجاراه. */
export interface LessonNeighbours {
  previous: CurriculumLesson | null;
  next: CurriculumLesson | null;
  /** ترتيبُه بدءاً من ١، أو `0` إن لم يُعرَف. */
  position: number;
  total: number;
}

/**
 * الدرسُ السابقُ والتالي، من القائمةِ التي بناها الخادم.
 *
 * ⚠️ **وحالةُ «التالي» تُقرَأُ ولا تُحسَب.** إغراءُ كتابةِ
 * `is_sequential && !completed ⇒ مقفول` في TypeScript كبير — وهو خطأ:
 * `Enrollment::accessTo()` يقرِّرُ الفتحَ من التسلسلِ **وبوّابةِ الاختبارِ
 * ومقعدِ التسجيلِ والمجموعة**، فقاعدةٌ مكتوبةٌ هنا تُخالفُه عندَ أوّلِ تسجيلِ
 * حصّةٍ في المسار: الزرُّ يقولُ «مفتوح» والبابُ يردُّ ‏٤٠٣. `state` و`lock`
 * جوابُ الخادمِ لكلِّ صفّ، وهما ما يُقرَأ.
 */
export function neighboursOf(tree: Curriculum, lessonUuid: string): LessonNeighbours {
  const flat = flattenLessons(tree);
  const index = flat.findIndex((item) => item.uuid === lessonUuid);

  if (index === -1) {
    return { previous: null, next: null, position: 0, total: flat.length };
  }

  return {
    previous: flat[index - 1] ?? null,
    next: flat[index + 1] ?? null,
    position: index + 1,
    total: flat.length,
  };
}
