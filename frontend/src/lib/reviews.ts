import { api } from "./api";

/**
 * The two review surfaces spec 010 adds (US4).
 *
 * ⚠️ THE AVERAGE COMES FROM THE SERVER AND IS NEVER RECOMPUTED HERE. Derived on
 * both sides, the two answers disagree the first time an axis is added — and the
 * screen showing the wrong one would be the student's own.
 *
 * ⚠️ AND ELIGIBILITY IS ASKED, NOT GUESSED. The rule is «enough sessions counted
 * as attended, inside a period», which the browser cannot know — a client-side
 * guess is wrong for anyone whose attendance it has not loaded, and it would
 * either hide a form the server accepts or offer one it refuses.
 */

/** The teacher's periodic assessment of one student. */
export interface PeriodicReview {
  uuid: string;
  period_start: string;
  period_end: string;
  commitment: number;
  participation: number;
  homework: number;
  improvement: number;
  average: number;
  note: string | null;
  is_published: boolean;
  published_at: string | null;
  teacher_name?: string | null;
}

export interface PeriodicReviewInput {
  student_uuid: string;
  period_start: string;
  period_end: string;
  commitment: number;
  participation: number;
  homework: number;
  improvement: number;
  note: string | null;
}

/** What the student's review form should offer, straight from the gate. */
export interface ReviewEligibility {
  eligible: boolean;
  attended_sessions: number;
  required_sessions: number;
  period_start: string | null;
  period_end: string | null;
  is_revision: boolean;
  reason: string | null;
}

/** The four axes of a periodic assessment, in the order every screen reads them. */
export const PERIODIC_AXES = [
  { key: "commitment", label: "الالتزام" },
  { key: "participation", label: "المشاركة" },
  { key: "homework", label: "الواجبات" },
  { key: "improvement", label: "التحسّن" },
] as const;

/** The three axes a student rates their teacher on (FR-031). */
export const TEACHER_AXES = [
  { key: "punctuality", label: "الالتزام بالمواعيد" },
  { key: "clarity", label: "جودة الشرح" },
  { key: "engagement", label: "التفاعل" },
] as const;

/*
 * ⚠️ `{ data: … }` FOR AN ARRAY ENDPOINT AND A BARE TYPE FOR AN OBJECT ONE, AND
 * THE ASYMMETRY IS IN `lib/api.ts` RATHER THAN IN THE API. The platform disables
 * resource wrapping, so a collection comes back as a bare array — and `request()`
 * normalises exactly that case back into `{ data: [...] }` while passing a single
 * object through untouched.
 *
 * ⚠️ SO CURLING THE API ANSWERS THE WRONG QUESTION. The endpoint really does send
 * a bare array; the client really does hand the page `{ data }`. Getting this
 * backwards costs a `rows.map is not a function` on first render — which is how
 * it was caught here, by opening the page, after a curl had "confirmed" the
 * opposite.
 */
export const reviews = {
  /** Everything this teacher has written for one student. */
  forStudent: (studentUuid: string) =>
    api.get<{ data: PeriodicReview[] }>(`/manage/students/${studentUuid}/reviews`),

  save: (input: PeriodicReviewInput) =>
    api.post<PeriodicReview>("/manage/periodic-reviews", input),

  publish: (uuid: string) =>
    api.post<PeriodicReview>(`/manage/periodic-reviews/${uuid}/publish`, {}),

  /**
   * The student's own assessments, or a child's for an authorised guardian.
   * Drafts never appear — the server refuses them.
   */
  mine: (studentUuid?: string) =>
    api.get<{ data: PeriodicReview[] }>(
      studentUuid ? `/students/me/reviews?student=${studentUuid}` : "/students/me/reviews",
    ),

  eligibility: (teacherUuid: string) =>
    api.get<ReviewEligibility>(`/teachers/${teacherUuid}/reviews/eligibility`),
};

/**
 * «١ أغسطس – ٣١ أغسطس» in the one place that formats it.
 *
 * ⚠️ `ar-QA` AND NOT `ar`. The bare tag renders Latin digits — «1 أغسطس» — and a
 * date is the one number a reader is most likely to see beside another. Same
 * locale string `lib/numerals.ts` pins for every other number on the platform.
 */
export function periodLabel(review: Pick<PeriodicReview, "period_start" | "period_end">): string {
  const format = (value: string) =>
    new Date(value).toLocaleDateString("ar-QA", { day: "numeric", month: "long" });

  return `${format(review.period_start)} – ${format(review.period_end)}`;
}

/*
|------------------------------------------------------------------------------
| The cumulative report card (US5)
|------------------------------------------------------------------------------
|
| ⚠️ NOTHING HERE RECOMPUTES A GRADE. Every percentage and every weight arrives
| already computed and already re-weighted, because the card is a snapshot
| (FR-052): a browser that derived the total from the components would show a
| different number the moment the teacher changed their weighting, on a document
| the family has already read. The client's whole job is to render.
*/

/** One teacher's contribution to a card (FR-041). */
export interface ReportCardSegment {
  uuid: string;
  teacher_name?: string | null;
  student_name?: string | null;
  period?: { start: string; end: string };
  /** Only the components that HAD data, with the weights as re-weighted. */
  components: Record<string, { pct: number; weight: number }>;
  attendance_pct: number | null;
  segment_pct: number | null;
}

export interface ReportCard {
  uuid: string;
  period_start: string;
  period_end: string;
  overall_pct: number | null;
  improvement_index: number | null;
  published_at: string | null;
  /** The PDF renders on a queue, so a card exists before its file does. */
  has_file: boolean;
  segments?: ReportCardSegment[];
}

export interface GradingScheme {
  uuid: string;
  period_start: string;
  period_end: string;
  weights: Record<string, number>;
}

/** The four grade components, in the order every screen reads them. */
export const GRADE_COMPONENTS = [
  { key: "exams", label: "الاختبارات" },
  { key: "homework", label: "الواجبات" },
  { key: "attendance", label: "الحضور" },
  { key: "participation", label: "المشاركة" },
] as const;

export const reportCards = {
  /** The student's own cards, or a child's for an authorised guardian. */
  mine: (studentUuid?: string) =>
    api.get<{ data: ReportCard[] }>(
      studentUuid ? `/report-cards?student=${studentUuid}` : "/report-cards",
    ),

  show: (uuid: string) => api.get<ReportCard>(`/report-cards/${uuid}`),

  /**
   * A five-minute signed URL for the PDF.
   *
   * ⚠️ FETCHED WITH THE TOKEN AND THEN NAVIGATED TO, never linked directly. The
   * download endpoint sits behind `auth:sanctum` and our token lives in
   * `localStorage`, so a plain `<a href>` sends no `Authorization` header and is
   * answered `401` — the same fact that put captions behind a grant URL in 019.
   */
  fileUrl: (uuid: string) =>
    /*
     * ⚠️ FETCH THE RETURNED URL, NEVER NAVIGATE TO IT. Since 2026-09-05 the file
     * route sits behind `auth:sanctum` as well as the signature, and it compares
     * the `reader` the signature names against the caller — without an account on
     * the request there was nothing to compare it to, and the link was a bearer
     * capability over a named minor's grades. `window.location`, `<a href>`, a
     * new tab and a print dialog all send no `Authorization` header and answer
     * 401. Use `download()` from `lib/api.ts`, which is here for this shape.
     */
    api.get<{ url: string; expires_in: number }>(`/report-cards/${uuid}/download`),

  /** What the TEACHER sees — their own segment, never the whole card. */
  segments: (studentUuid?: string) =>
    api.get<{ data: ReportCardSegment[] }>(
      studentUuid
        ? `/manage/report-card-segments?student=${studentUuid}`
        : "/manage/report-card-segments",
    ),
};

export const gradingSchemes = {
  list: () => api.get<{ data: GradingScheme[] }>("/manage/grading-schemes"),

  save: (input: {
    course_uuid?: string | null;
    period_start: string;
    period_end: string;
    weights: Record<string, number>;
  }) => api.post<GradingScheme>("/manage/grading-schemes", input),
};

/** «١ أغسطس ٢٠٢٦ – ٣١ أغسطس ٢٠٢٦» — `ar-QA` for the reason above. */
export function cardPeriodLabel(card: Pick<ReportCard, "period_start" | "period_end">): string {
  const format = (value: string) =>
    new Date(value).toLocaleDateString("ar-QA", {
      day: "numeric",
      month: "long",
      year: "numeric",
    });

  return `${format(card.period_start)} – ${format(card.period_end)}`;
}
