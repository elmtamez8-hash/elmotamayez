import { api } from "./api";
import type { PracticePaper } from "./practice";

/**
 * The student's mistake notebook, and the paper built from it.
 *
 * ⚠️ IT SPANS EVERY TEACHER THE STUDENT STUDIES WITH, AND EACH ROW NAMES ITS
 * OWN. It did not: the endpoint answered for «the workspace the student is
 * currently in» and refused with a `422` when there was none — which is every
 * real student, since a student is a member of no workspace. So the notebook
 * did not exist for anybody it was written for. What the per-teacher rule
 * protects is that nobody be shown half their mistakes called all of them, and
 * that no question be read inside another teacher's context: a list that spans
 * teachers, names each row's, and can narrow to one keeps both.
 *
 * ⚠️ AND THE FILTER OPTIONS COME FROM `mistakes.filters()`, NEVER FROM ANOTHER
 * LIST. The server derives them from the notebook's own query, so an offered
 * option always answers at least one row. Building the picker from the nearest
 * list to hand is what put boards on the leaderboard screen that the API
 * refused — assembling one here from, say, `/enrollments` would repeat it.
 */

export interface Mistake {
  uuid: string;
  question: {
    uuid: string;
    type: "mcq" | "true_false" | "essay";
    content: string;
    /** Optional on the bank — a notebook that required it would go blank on
     *  every question a teacher imported in bulk. */
    explanation: string | null;
    concept: { uuid: string; name: string } | null;
    lesson: { uuid: string; title: string } | null;
  } | null;
  /** Their essay text, or the options they picked — as words, not ids. */
  your_answer: string | string[];
  correct_answer: string[];
  /** Whose question this is. Null only if the workspace row has vanished. */
  teacher: { uuid: string; name: string } | null;
  is_resolved: boolean;
  times_wrong: number;
  answered_at: string;
}

/** One row of the filter bar, the same shape for all four facets. */
export interface FilterOption {
  uuid: string;
  label: string;
}

export interface MistakeFilterOptions {
  teachers: FilterOption[];
  courses: FilterOption[];
  exams: FilterOption[];
  concepts: FilterOption[];
  /**
   * Whether anything is left to build a revision paper from.
   *
   * ⚠️ A DIFFERENT QUESTION FROM THE FACETS, and it is answered against the
   * STANDING set whatever view the bar was asked for. The facets follow what is
   * on screen; the practice button must not, or «الكل» would offer a paper to a
   * student who has fixed everything — a 422 they cannot act on.
   */
  has_standing: boolean;
}

export interface MistakeFilters {
  /** A workspace uuid — the teacher. */
  teacher?: string;
  course?: string;
  exam?: string;
  concept?: string;
  lesson?: string;
  from?: string;
  to?: string;
  include_resolved?: boolean;
}

function query(filters: MistakeFilters): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === "" || value === false) continue;
    params.set(key, value === true ? "1" : String(value));
  }

  const q = params.toString();

  return q ? `?${q}` : "";
}

export const mistakes = {
  /**
   * What the bar may offer.
   *
   * ⚠️ ASKED OF THE SERVER RATHER THAN DERIVED IN THE BROWSER. Only the server
   * knows which teachers, courses, exams and concepts the reader actually has a
   * mistake in — and an option that answers an empty list is a control that
   * wastes a tap and teaches the reader not to trust the bar.
   *
   * ⚠️ AND IT TAKES THE VIEW. `include_resolved` is not a narrowing, it is which
   * list is on screen: derived from the standing set alone the bar vanished for
   * anybody who had fixed everything, and «الكل» then listed their whole
   * notebook with nothing to filter it by.
   */
  filters: (includeResolved = false) =>
    api.get<MistakeFilterOptions>(
      `/mistakes/filters${includeResolved ? "?include_resolved=1" : ""}`,
    ),

  list: (filters: MistakeFilters = {}) =>
    api.get<{ data: Mistake[]; meta: { total: number; current_page: number; last_page: number } }>(
      `/mistakes${query(filters)}`,
    ),

  /**
   * Builds a practice attempt from the standing mistakes and returns it.
   *
   * The attempt belongs to no exam and is marked practice, so it spends no
   * official attempt and enters no grade report.
   */
  /**
   * ⚠️ THE TEACHER TRAVELS IN THE QUERY STRING, NOT THE BODY. The server reads
   * it with `$request->string('teacher')` to decide which bank the paper is
   * drawn from — a paper belongs to one teacher even though the notebook no
   * longer does, and with several to choose from and none named the server
   * refuses rather than picking one.
   */
  practice: (filters: MistakeFilters = {}, count = 10) =>
    api.post<{ data: PracticePaper }>(`/practice/from-mistakes${query(filters)}`, {
      ...filters,
      count,
    }),
};
