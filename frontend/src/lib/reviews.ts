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

export const reviews = {
  /** Everything this teacher has written for one student. */
  forStudent: (studentUuid: string) =>
    api.get<{ data: PeriodicReview[] }>(`/manage/students/${studentUuid}/reviews`),

  save: (input: PeriodicReviewInput) =>
    api.post<{ data: PeriodicReview }>("/manage/periodic-reviews", input),

  publish: (uuid: string) =>
    api.post<{ data: PeriodicReview }>(`/manage/periodic-reviews/${uuid}/publish`, {}),

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
