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
  /*
    ⚠️ WHICH SUBJECT AND WITH WHOM — absent from this payload until now, on a
    list that spans every teacher the student studies with. Two teachers setting
    «واجب الفصل الثالث» produced two identical rows with nothing between them.
    Optional because the teacher's own list does not eager-load them.
  */
  course?: { uuid: string; title: string } | null;
  teacher?: { uuid: string; name: string } | null;
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

export interface AssignmentFilterOptions {
  teachers: { uuid: string; label: string }[];
  courses: { uuid: string; label: string }[];
  /** «الرياضيات» across every teacher who sets homework in it. */
  subjects: { uuid: string; label: string }[];
  /**
   * ⚠️ EACH ONE CARRIES ITS COURSE, and the card needs that more than the filter
   * does. A student holds one open membership per course, so narrowing by a group
   * selects what narrowing by its course would — but the group's NAME is how they
   * refer to their own timetable, and `course_uuid` is what lets a card print it
   * without a lookup per row.
   */
  cohorts: { uuid: string; label: string; course_uuid: string }[];
}

export const assignments = {
  /**
   * ⚠️ `per_page` IS SENT, AND THE PAGE READS `meta`. The list rendered page one
   * and nothing else — twenty rows, silently, with no control to reach the rest:
   * a student with a full term of homework simply could not see the older half.
   */
  list: (
    params: {
      page?: number;
      perPage?: number;
      teacher?: string;
      course?: string;
      subject?: string;
      cohort?: string;
    } = {},
  ) => {
    const query = new URLSearchParams({
      page: String(params.page ?? 1),
      per_page: String(params.perPage ?? 30),
    });

    if (params.teacher !== undefined && params.teacher !== "") query.set("teacher", params.teacher);
    if (params.course !== undefined && params.course !== "") query.set("course", params.course);
    if (params.subject !== undefined && params.subject !== "") query.set("subject", params.subject);
    if (params.cohort !== undefined && params.cohort !== "") query.set("cohort", params.cohort);

    return api.get<{ data: Assignment[]; meta: Meta }>(`/assignments?${query.toString()}`);
  },

  /** The pickers, derived on the server from this same list's predicate. */
  filters: () => api.get<{ data: AssignmentFilterOptions }>("/assignments/filters"),

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
