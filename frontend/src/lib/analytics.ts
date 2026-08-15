import { api } from "./api";

/**
 * Item analysis: which questions students get wrong, and which ideas.
 *
 * The types mirror QuestionStatResource and ConceptStatResource field for
 * field. Read the PHP resource before changing one.
 *
 * ⚠️ `wrong_pct` IS `null` WHEN TOO FEW STUDENTS HAVE SAT THE QUESTION, and
 * `null` is not zero. Never write `stat.wrong_pct ?? 0` — the screen would then
 * tell a teacher that nobody struggles with a question two people answered, and
 * a teacher who believes that deletes it. Branch on `has_enough_data`.
 */

export interface QuestionStat {
  question: {
    uuid: string;
    content: string;
    is_active: boolean;
    concept: { uuid: string; name: string } | null;
  } | null;
  attempts_count: number;
  wrong_count: number;
  wrong_pct: number | null;
  has_enough_data: boolean;
  computed_at: string;
}

export interface ConceptStat {
  concept?: { uuid: string; name: string } | null;
  /** True on the row that covers the concept across every lesson. */
  is_overall: boolean;
  lesson?: { uuid: string; title: string } | null;
  attempts_count: number;
  wrong_count: number;
  wrong_pct: number | null;
  has_enough_data: boolean;
  computed_at: string;
}

type Meta = { total: number; current_page: number; last_page: number; scope: string };

export const analytics = {
  questions: (page = 1) =>
    api.get<{ data: QuestionStat[]; meta: Meta }>(`/manage/analytics/questions?page=${page}`),

  concepts: () => api.get<{ data: ConceptStat[]; meta: { scope: string } }>("/manage/analytics/concepts"),
};

/** A rate as text, or the reason there is no rate. */
export function ratioLabel(stat: { wrong_pct: number | null; has_enough_data: boolean }): string {
  return stat.has_enough_data && stat.wrong_pct !== null
    ? `${stat.wrong_pct.toFixed(1)}٪`
    : "بيانات غير كافية";
}
