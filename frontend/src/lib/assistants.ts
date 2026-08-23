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

export interface AssistantCourse {
  uuid: string;
  title: string;
}

export interface AssistantAssignment {
  uuid: string;
  assistant: { uuid: string; name: string } | null;
  workspace: { uuid: string; name: string } | null;
  is_confined: boolean;
  courses: AssistantCourse[];
  revoked_at: string | null;
}

export const assistants = {
  list: () => api.get<{ data: AssistantAssignment[] }>("/manage/assistants"),

  /** An empty array takes the confinement off — it does not remove every course. */
  setScope: (uuid: string, courseUuids: string[]) =>
    api.put<{ data: AssistantAssignment }>(`/manage/assistants/${uuid}/scope`, {
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
