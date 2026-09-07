import { api } from "./api";

/**
 * The private hour: a student asks, a person answers (spec 023 · US3).
 *
 * ⚠️ NOTHING HERE CARRIES AN AMOUNT. A credit's price is the teacher's approved
 * settlement rate plus two platform constants, so a total shown to either side is
 * solvable for the other's rate. What travels is a DURATION — which is what
 * «حصة» means to both of them.
 *
 * The types mirror `PrivateSessionRequestResource` field for field. Read the PHP
 * resource before changing one: a type that claims a field the API does not send
 * renders a blank with no error anywhere.
 */

export type PrivateSessionStatus =
  | "pending"
  | "accepted"
  | "rejected"
  | "withdrawn"
  | "expired";

export interface PrivateSessionRequest {
  uuid: string;
  status: PrivateSessionStatus;
  /** An absolute instant. Rendered in the reader's own timezone. */
  starts_at: string;
  duration_minutes: number;
  expires_at: string;
  /** ⚠️ Mandatory on a rejection, and shown — the student reads it (FR-018). */
  decision_reason: string | null;
  decided_at: string | null;
  created_at: string;
  course?: { uuid: string; title: string } | null;
  student?: { uuid: string; name: string } | null;
  class_session_uuid?: string | null;
}

export const privateSessions = {
  /** The student's own asks, live and settled. */
  mine: () => api.get<{ data: PrivateSessionRequest[] }>("/private-session-requests"),

  /**
   * ⚠️ ONE FIELD. The duration is declared by the teacher on the course and READ
   * by the student (FR-016أ); sending it would let the browser decide what a
   * credit buys and how much of a teacher's calendar is taken.
   */
  request: (courseUuid: string, startsAt: string) =>
    api.post<PrivateSessionRequest>(
      `/courses/${courseUuid}/private-session-requests`,
      { starts_at: startsAt },
    ),

  withdraw: (requestUuid: string) =>
    api.delete<PrivateSessionRequest>(`/private-session-requests/${requestUuid}`),

  /**
   * The teacher's queue. Pending unless a status is named.
   *
   * ⚠️ `meta` IS PART OF THE ANSWER, and the dashboard reads only that half.
   * The queue paginates at a fixed twenty, so «how many are waiting» counted
   * from `data.length` stops at twenty and reassures a teacher who has sixty.
   * There is no `per_page` to send — `paginate(20)` does not read one.
   */
  queue: (status?: PrivateSessionStatus) =>
    api.get<{ data: PrivateSessionRequest[]; meta: { total: number } }>(
      status
        ? `/manage/private-session-requests?status=${status}`
        : "/manage/private-session-requests",
    ),

  accept: (requestUuid: string) =>
    api.post<PrivateSessionRequest>(
      `/manage/private-session-requests/${requestUuid}/decide`,
      { accept: true },
    ),

  /** ⚠️ `reason` is REQUIRED — the student reads it, and the API refuses without it. */
  reject: (requestUuid: string, reason: string) =>
    api.post<PrivateSessionRequest>(
      `/manage/private-session-requests/${requestUuid}/decide`,
      { accept: false, decision_reason: reason },
    ),
};
