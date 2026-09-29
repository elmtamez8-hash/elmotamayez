import { api } from "./api";
import { arabicDecimal } from "./numerals";

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

/**
 * A rate as text, or the reason there is no rate.
 *
 * `arabicDecimal`, never `toFixed`: `(12.5).toFixed(1)` is «12.5» — Latin digits
 * beside the Arabic-Indic counts in the same table row.
 */
export function ratioLabel(stat: { wrong_pct: number | null; has_enough_data: boolean }): string {
  return stat.has_enough_data && stat.wrong_pct !== null
    ? `${arabicDecimal(stat.wrong_pct, 1)}٪`
    : "بيانات غير كافية";
}
