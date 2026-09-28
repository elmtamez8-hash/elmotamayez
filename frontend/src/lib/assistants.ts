import { api } from "./api";

/**
 * The teacher's team (spec 010 · US1).
 *
 * ⚠️ `is_confined` IS SENT BY THE SERVER AND IS NOT DERIVED FROM `courses.length`.
 * An empty course list means EVERY course, never none — an assistant is invited
 * before anybody decides what they will teach — and a screen that inferred the
 * opposite from a length would tell a teacher their new assistant is locked out
 * of everything on the day they accepted.
 *
 * ⚠️ AND THERE IS NO `create`. Membership is written by the invitation flow,
 * which is shipped and has its own screen; the assignment rides it. A second way
 * in would be a second membership story.
 */

/**
 * A course as the team screen shows it — on a card and in the picker, one shape
 * for both (`AssistantCourseResource`). No price: an assistant never sees money.
 * The details are optional so a caller that only knows the title still types.
 */
export interface AssistantCourse {
  uuid: string;
  title: string;
  cover_url?: string | null;
  /** `draft` · `published` · `archived` — through `statusLabel()`, never raw. */
  status?: string;
  /** The course's author. Shown only when the workspace has several teachers. */
  teacher?: { name: string } | null;
}

/** How many teachers the workspace has — above one, each course names its own. */
export interface TeamMeta {
  teachers_count: number;
}

export interface AssistantAssignment {
  uuid: string;
  assistant: { uuid: string; name: string } | null;
  workspace: { uuid: string; name: string } | null;
  is_confined: boolean;
  courses: AssistantCourse[];
  /**
   * Scoped courses that were deleted since. They still confine (the server reads
   * the row, not the course), so a card must say so rather than show nothing.
   */
  unavailable_courses_count?: number;
  revoked_at: string | null;
}

export const assistants = {
  list: () => api.get<{ data: AssistantAssignment[]; meta?: TeamMeta }>("/manage/assistants"),

  /**
   * The picker's courses: this workspace's live ones, drafts included — the
   * same set the scope write accepts, rather than the authoring index.
   */
  courses: () =>
    api.get<{ data: AssistantCourse[]; meta?: TeamMeta }>("/manage/assistants/courses"),

  /** An empty array takes the confinement off — it does not remove every course. */
  setScope: (uuid: string, courseUuids: string[]) =>
    api.put<AssistantAssignment>(`/manage/assistants/${uuid}/scope`, {
      courses: courseUuids,
    }),

  revoke: (uuid: string) => api.delete<void>(`/manage/assistants/${uuid}`),

  /** Where the signed-in user is an assistant, and on what. */
  mine: () => api.get<{ data: AssistantAssignment[] }>("/assistants/me"),
};

/** The confinement in one sentence, for a row that has to be scannable. */
export function scopeSummary(assignment: AssistantAssignment): string {
  if (assignment.revoked_at !== null) return "أُنهيت المهمة";

  if (!assignment.is_confined) return "كل الكورسات";

  return assignment.courses.map((course) => course.title).join(" · ");
}
