import { api } from "./api";

/**
 * Homework: what is due, what came in, and what it scored.
 *
 * ⚠️ `due_at` IS THE ASSIGNMENT'S OWN DATE AND NOBODY'S EFFECTIVE ONE. A student
 * with an accommodation has a later deadline, and it lives on their own
 * submission — a shared object that quietly differed per reader would announce
 * that the accommodation exists (FR-056).
 *
 * ⚠️ AND `state` / `submitted_at` ARRIVE ONLY FOR THE ROW'S OWNER AND ITS
 * MARKER. A hand-in stamped after the deadline and labelled `on_time` tells any
 * reader who can subtract that its owner had an extension, so the server omits
 * the pair rather than sending it with a redacted date.
 */

export type SubmissionState = "on_time" | "late" | "missed" | "pending";

export interface Submission {
  uuid: string;
  assignment: { uuid: string; title: string; points: number } | null;
  student: { uuid: string; name: string } | null;
  answer_text: string | null;
  has_file: boolean;
  score: number | null;
  feedback: string | null;
  graded_at: string | null;
  is_graded: boolean;
  late_penalty_applied_pct: number | null;
  state?: SubmissionState;
  submitted_at?: string | null;
  late_by_minutes?: number;
  extension_until?: string | null;
}

export interface Assignment {
  uuid: string;
  title: string;
  description: string | null;
  points: number;
  due_at: string | null;
  submission_type: "text" | "file" | "questions";
  late_policy: "accept" | "reject" | "penalty";
  late_penalty_pct_per_day: number;
  late_penalty_cap_pct: number;
  status: "draft" | "published";
  published_at: string | null;
  submitted_count: number | null;
  pending_count: number | null;
  my_submission: Submission | null;
}

type Meta = { total: number; current_page: number; last_page: number };

export const assignments = {
  list: (page = 1) => api.get<{ data: Assignment[]; meta: Meta }>(`/assignments?page=${page}`),

  show: (uuid: string) => api.get<{ data: Assignment }>(`/assignments/${uuid}`),

  submissions: (uuid: string) =>
    api.get<{ data: Submission[] }>(`/manage/assignments/${uuid}/submissions`),

  // FormData rather than JSON: the hand-in may carry a file, and one call site
  // for both keeps the two paths from drifting.
  submit: (uuid: string, body: { answer_text?: string; file?: File }) => {
    const form = new FormData();

    if (body.answer_text !== undefined) form.append("answer_text", body.answer_text);
    if (body.file !== undefined) form.append("file", body.file);

    // `upload`, not `post`: it strips the JSON Content-Type so the browser can
    // build the multipart boundary itself.
    return api.upload<{ data: Submission }>(`/assignments/${uuid}/submissions`, form);
  },

  grade: (submissionUuid: string, score: number, feedback?: string) =>
    api.post<{ data: Submission }>(`/manage/submissions/${submissionUuid}/grade`, {
      score,
      feedback,
    }),

  publish: (uuid: string) => api.post<{ data: Assignment }>(`/manage/assignments/${uuid}/publish`),
};

/** What the student is looking at, said in words rather than a colour. */
export function stateLabel(state: SubmissionState | undefined): string {
  switch (state) {
    case "on_time":
      return "سُلّم في الموعد";
    case "late":
      return "سُلّم متأخراً";
    case "missed":
      return "لم يُسلَّم";
    case "pending":
      return "بانتظار التسليم";
    default:
      return "—";
  }
}
