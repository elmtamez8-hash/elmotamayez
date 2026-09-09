import { api } from "./api";

/**
 * Moving ONE lesson, with the teacher's consent (spec 049).
 *
 * ⚠️ THERE IS NO «TEMPORARY» FLAG ANYWHERE, and that is the design rather than a
 * gap. A group's timetable is a list of dated sessions — not references to a
 * weekly availability slot — so moving one row moves one lesson and the next
 * week is a different row nothing touched. «والحصّةُ التاليةُ في موعدِها
 * الطبيعيّ» is a property of the data, so there is nothing here to restore.
 *
 * The types mirror `SessionRescheduleRequestResource` field for field. Read the
 * PHP resource before changing one: a type that claims a field the API does not
 * send renders a blank with no error anywhere.
 */

export type RescheduleStatus = "pending" | "approved" | "rejected";

export interface RescheduleRequest {
  uuid: string;
  status: RescheduleStatus;
  /** Absolute instants. Rendered in the reader's own timezone. */
  from_starts_at: string;
  to_starts_at: string;
  student_reason: string | null;
  /** ⚠️ Mandatory on a rejection, and shown — the student reads it. */
  decision_reason: string | null;
  decided_at: string | null;
  created_at: string;
  session?: { uuid: string; title: string } | null;
  student?: { uuid: string; name: string } | null;
}

export const rescheduleRequests = {
  /** The student's own asks, live and settled. */
  mine: () => api.get<{ data: RescheduleRequest[] }>("/session-reschedule-requests"),

  ask: (sessionUuid: string, toStartsAt: string, reason?: string) =>
    api.post<RescheduleRequest>(`/class-sessions/${sessionUuid}/reschedule-requests`, {
      to_starts_at: toStartsAt,
      reason: reason ?? null,
    }),

  /**
   * The teacher's queue. Pending unless a status is named.
   *
   * ⚠️ `meta` IS PART OF THE ANSWER. The endpoint is paginated, so a reader that
   * takes `data` alone silently stops at twenty with nothing saying there is a
   * twenty-first.
   */
  queue: (status?: RescheduleStatus) =>
    api.get<{ data: RescheduleRequest[]; meta?: { total?: number } }>(
      `/manage/session-reschedule-requests${status === undefined ? "" : `?status=${status}`}`,
    ),

  approve: (uuid: string) =>
    api.post<RescheduleRequest>(`/manage/session-reschedule-requests/${uuid}/decide`, {
      approve: true,
    }),

  /**
   * ⚠️ THE REASON IS REQUIRED AND THE SERVER SAYS SO. A silent refusal is
   * indistinguishable from a request still waiting, so it is submitted again for
   * ever — which is a queue the teacher then clears twice.
   */
  reject: (uuid: string, reason: string) =>
    api.post<RescheduleRequest>(`/manage/session-reschedule-requests/${uuid}/decide`, {
      approve: false,
      decision_reason: reason,
    }),
};
