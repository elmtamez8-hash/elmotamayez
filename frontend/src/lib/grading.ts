import { api } from "./api";

/**
 * The grading board: papers waiting on a person, and what that person does.
 *
 * ⚠️ `auto_score` IS NOT THE STUDENT'S RESULT, and the screen must never label
 * it as one. It is the machine-marked half of a paper whose essays nobody has
 * read — on a paper worth 11 with one multiple-choice question, a student who
 * wrote flawless essays shows 9٪ here.
 *
 * ⚠️ AND `student` IS ABSENT RATHER THAN NULL WHEN ANONYMITY IS ON (FR-033).
 * The server drops the key; nothing here reconstructs a placeholder name, which
 * would be a redaction the payload never asked for.
 */

export interface GradingQueueRow {
  uuid: string;
  exam_title: string | null;
  submitted_at: string;
  auto_score: number;
  pending_count: number;
  student?: { uuid: string; name: string };
  is_anonymous: boolean;
}

export interface RubricCriterion {
  id: number;
  label: string;
  max_points: number;
}

export interface GradingMark {
  criterion_id: number | null;
  points: number;
  comment: string | null;
  revision_reason?: string | null;
}

export interface GradingAnswer {
  uuid: string;
  question: { uuid: string; content: string; explanation: string | null } | null;
  answer_text: string | null;
  points_possible: number;
  points_awarded: number;
  is_graded: boolean;
  graded_at: string | null;
  grading_version: number;
  criteria: RubricCriterion[];
  marks: GradingMark[];
}

export interface GradingPaper {
  uuid: string;
  exam_title: string | null;
  submitted_at: string;
  status: string;
  auto_score: number;
  is_anonymous: boolean;
  student: { uuid: string; name: string } | null;
  answers: GradingAnswer[];
}

type Meta = { total: number; current_page: number; last_page: number };

/** What the grader is submitting for one answer. */
export interface MarkInput {
  criterion_id?: number | null;
  points: number;
  comment?: string | null;
}

export const grading = {
  queue: (page = 1, perPage = 20) =>
    api.get<{ data: GradingQueueRow[]; meta: Meta }>(
      `/manage/grading/queue?page=${page}&per_page=${perPage}`,
    ),

  paper: (attemptUuid: string) =>
    api.get<{ data: GradingPaper }>(`/manage/grading/attempts/${attemptUuid}`),

  grade: (answerUuid: string, marks: MarkInput[]) =>
    api.post<{ data: GradingAnswer }>(`/manage/grading/answers/${answerUuid}`, { marks }),

  // A revision carries its reason on the wire because it carries it in the
  // table: FR-032 makes a changed grade with no stated cause impossible to
  // answer when the student asks.
  revise: (answerUuid: string, marks: MarkInput[], reason: string) =>
    api.patch<{ data: GradingAnswer }>(`/manage/grading/answers/${answerUuid}`, { marks, reason }),
};
