import { api } from "./api";

/**
 * The adaptive practice path (spec 012 · US1).
 *
 * ⚠️ NOTHING HERE COUNTS EITHER. Every answer is a row under an `is_practice`
 * attempt with no exam behind it, so a session spends no official attempt and
 * enters no grade report — and it DOES enter the mistake notebook, which is the
 * whole point: the two facts come from the same table by construction.
 */

export type AdaptiveDifficulty = "easy" | "medium" | "hard";

export interface AdaptiveQuestion {
  /** ⚠️ The address of an answer, and NOT `order` — two items can share an order. */
  question_id: number;
  order: number;
  content: string;
  points: number;
  difficulty: AdaptiveDifficulty | null;
  options: { id: number; content: string }[];
}

export interface AdaptiveSession {
  uuid: string;
  status: "running" | "mastered" | "ended";
  status_label: string;
  difficulty: AdaptiveDifficulty;
  /**
   * ⚠️ READ FROM THE PAYLOAD, NEVER DERIVED IN THE BROWSER. It is the highest
   * difficulty THIS concept has a question at for THIS student — a client that
   * assumed "hard" would show a bar no action of theirs can reach in a concept
   * whose questions stop at medium. Mastery is measured here.
   */
  ceiling_difficulty: AdaptiveDifficulty;
  correct_streak: number;
  served_count: number;
  max_questions: number;
  /** The live threshold, stated rather than hidden (FR-003). */
  mastery_after: number;
  concept: { uuid: string; name: string };
  mastered_at: string | null;
  ended_at: string | null;
  score: number | null;
}

export interface AdaptiveStep {
  result: {
    is_correct: boolean;
    correct_option_ids: number[];
    explanation: string | null;
  };
  session: AdaptiveSession;
  difficulty_changed: boolean;
  /** FR-004 said out loud: why the level moved, including a walk on exhaustion. */
  difficulty_note: string | null;
  question: AdaptiveQuestion | null;
}

export interface AdaptiveStart {
  session: AdaptiveSession;
  /** True when the server handed back a session that was already open (409). */
  resumed: boolean;
  question: AdaptiveQuestion | null;
}

export interface AdaptiveConcept {
  uuid: string;
  name: string;
  teacher: { uuid: string; name: string };
  question_count: number;
  ceiling_difficulty: AdaptiveDifficulty;
  mastered_at: string | null;
}

export const adaptive = {
  /**
   * ⚠️ AN EMPTY LIST IS A STATE, NOT AN ERROR. The feature switch filters this
   * list per teacher rather than refusing it, so a student none of whose
   * teachers has switched it on gets `[]` and a sentence — never a 403 about a
   * page that is not a mistake.
   */
  concepts: () => api.get<{ data: AdaptiveConcept[] }>("/practice/adaptive/concepts"),

  start: (concept: string, teacher: string) =>
    api.post<{ data: AdaptiveStart }>("/practice/adaptive", { concept, teacher }),

  answer: (session: string, question_id: number, option_ids: number[]) =>
    api.post<{ data: AdaptiveStep }>(`/practice/adaptive/${session}/answer`, {
      question_id,
      option_ids,
    }),

  end: (session: string) =>
    api.post<{ data: { session: AdaptiveSession } }>(`/practice/adaptive/${session}/end`, {}),
};
