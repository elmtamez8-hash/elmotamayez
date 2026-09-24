import { api } from "./api";

/**
 * Standing arrangements for one student: extra time on exams, extra days on
 * homework (spec 008 · FR-053 · FR-055). Mirrors `AccommodationController`.
 *
 * ⚠️ TEACHER-FACING ONLY, AND THERE IS NO STUDENT READ ON PURPOSE (FR-056): the
 * holder sees a later deadline and a longer timer on their own work, never a
 * list a classmate could glance at.
 *
 * ⚠️ «NOT YOUR STUDENT» ANSWERS 404, THE SAME AS «NO SUCH PERSON». That sameness
 * is the guard against probing uuids, so the screen offers only the teacher's own
 * students rather than trying to explain the refusal better.
 */
export interface Accommodation {
  uuid: string;
  student: { uuid: string; name: string } | null;
  extra_time_pct: number;
  extended_days: number;
  reason: string;
  granted_at: string;
}

/** Keys copied from `GrantAccommodationRequest`, one for one. */
export interface GrantAccommodationPayload {
  student_uuid: string;
  extra_time_pct: number;
  extended_days: number;
  reason: string;
}

export const accommodations = {
  list: () => api.get<{ data: Accommodation[] }>("/manage/accommodations"),

  grant: (payload: GrantAccommodationPayload) =>
    api.post<{ data: { uuid: string; extra_time_pct: number; extended_days: number } }>(
      "/manage/accommodations",
      payload,
    ),

  revoke: (uuid: string) =>
    api.delete<{ data: { uuid: string; revoked: boolean } }>(`/manage/accommodations/${uuid}`),
};
