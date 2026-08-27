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
  /** One teacher's bank. Required once the student studies with more than one. */
  teacher?: string;
  course?: string;
  subject?: string;
}

/**
 * What this student may narrow a paper by — derived on the server from the SAME
 * pool the paper is drawn from.
 *
 * ⚠️ THE PAGE USED TO ASK `/manage/bank/concepts` FOR THIS, a teacher route that
 * answers a student `403` — so the one filter «درّب نفسك» offered was empty for
 * every person who could see it, for two specs, with the page's own comment
 * saying it «degrades to any concept». It did not degrade; it never worked.
 */
export interface PracticeFilterOptions {
  teachers: { uuid: string; label: string }[];
  courses: { uuid: string; label: string }[];
  /** One subject may span several of a teacher's courses — a different axis. */
  subjects: { uuid: string; label: string }[];
  concepts: { uuid: string; label: string }[];
  /** Whether the pool can answer at all — an empty bank is a sentence, not a form. */
  has_questions: boolean;
}

export const practice = {
  /** `teacher` narrows which bank the lists describe; omitted, the server answers
   *  for the only teacher when there is one. */
  filters: (teacher?: string) =>
    api.get<{ data: PracticeFilterOptions }>(
      `/practice/filters${teacher !== undefined && teacher !== "" ? `?teacher=${encodeURIComponent(teacher)}` : ""}`,
    ),

  build: (criteria: SelfExamCriteria) =>
    api.post<{ data: PracticePaper }>("/practice/exams", {
      ...criteria,
      // An empty select is "no preference", not a filter on the empty string.
      concept_id: criteria.concept_id === "" ? undefined : criteria.concept_id,
      difficulty: criteria.difficulty === "" ? undefined : criteria.difficulty,
      teacher: criteria.teacher === "" ? undefined : criteria.teacher,
      course: criteria.course === "" ? undefined : criteria.course,
      subject: criteria.subject === "" ? undefined : criteria.subject,
    }),

  submit: (attemptUuid: string, answers: { question_id: number; selected_option_ids: number[] }[]) =>
    api.post<{ uuid: string }>(`/attempts/${attemptUuid}/submit`, { answers }),

  result: (attemptUuid: string) =>
    api.get<{ data: PracticeResult }>(`/practice/attempts/${attemptUuid}/result`),
};
