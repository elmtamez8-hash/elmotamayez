import { api } from "./api";

/**
 * The student's mistake notebook, and the paper built from it.
 *
 * ⚠️ THE NOTEBOOK IS PER TEACHER, never merged across the teachers a student
 * studies with. The API answers for the workspace the student is currently in;
 * a screen that stitched several together would show one teacher's question
 * inside another's context, and would call half the mistakes all of them.
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
  is_resolved: boolean;
  times_wrong: number;
  answered_at: string;
}

export interface MistakeFilters {
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

/** One question of a built paper, as the student sees it — no answer key. */
export interface PracticeQuestion {
  id: number;
  type: string;
  content: string;
  points: number;
  options: { id: number; content: string }[];
}

export const mistakes = {
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
  practice: (filters: MistakeFilters = {}, count = 10) =>
    api.post<{ data: { uuid: string; status: string }; questions: PracticeQuestion[] }>("/practice/from-mistakes", {
      ...filters,
      count,
    }),
};
