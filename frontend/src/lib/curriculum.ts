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
