import { api } from "./api";

/**
 * The student sets themselves a paper from their teacher's bank, and reads it
 * back marked.
 *
 * ⚠️ NOTHING HERE COUNTS. The attempt carries no exam, so it spends none of the
 * student's official attempts and enters no grade report. That is what makes it
 * safe to offer a "generate another" button beside the result.
 */

export interface PracticeQuestion {
  id: number;
  type: string;
  content: string;
  points: number;
  options: { id: number; content: string }[];
}

export interface PracticePaper {
  uuid: string;
  status: string;
  questions: PracticeQuestion[];
  /** What they asked for, and what the bank could give — FR-023 needs both. */
  requested_count: number;
  delivered_count: number;
  duration_minutes: number;
}

export interface PracticeReview {
  id: number;
  content: string;
  was_answered: boolean;
  is_correct: boolean;
  your_answer: string[];
  correct_answer: string[];
  explanation: string | null;
}

export interface PracticeResult {
  uuid: string;
  status: string;
  score: number;
  questions: PracticeReview[];
}

export interface SelfExamCriteria {
  count?: number;
  duration_minutes?: number;
  concept_id?: string;
  difficulty?: "easy" | "medium" | "hard" | "";
}

export const practice = {
  build: (criteria: SelfExamCriteria) =>
    api.post<{ data: PracticePaper }>("/practice/exams", {
      ...criteria,
      // An empty select is "no preference", not a filter on the empty string.
      concept_id: criteria.concept_id === "" ? undefined : criteria.concept_id,
      difficulty: criteria.difficulty === "" ? undefined : criteria.difficulty,
    }),

  submit: (attemptUuid: string, answers: { question_id: number; selected_option_ids: number[] }[]) =>
    api.post<{ uuid: string }>(`/attempts/${attemptUuid}/submit`, { answers }),

  result: (attemptUuid: string) =>
    api.get<{ data: PracticeResult }>(`/practice/attempts/${attemptUuid}/result`),
};
