import { api } from "./api";

/**
 * Groups: a course may be taught to several of them, and a student belongs to
 * exactly one at a time (spec 021 · §ب / §ج of `contracts/api.md`).
 *
 * ⚠️ THE ROUTES BELOW DO NOT EXIST YET — they arrive with US3. This file is the
 * client half of a contract that is already written down, placed in the Setup
 * phase so the first screen to need it reaches for one spelling of the payload
 * rather than inventing a second beside it.
 *
 * The types mirror the resources field for field. Read the PHP resource before
 * changing one: a type that claims a field the API does not send renders a blank
 * with no error anywhere — which is how `/enrollments` spent four phases showing
 * «كورس رقم » and nothing after it, and how six screens across four modules
 * shipped listing every student as «» when a constrained eager load omitted the
 * two columns the `name` accessor reads.
 */

export interface CohortMembership {
  cohort_uuid: string;
  cohort_name: string;
  joined_at: string;
}

export interface CohortTransferRequest {
  uuid: string;
  status: "pending" | "approved" | "rejected" | "dropped";
  student_reason: string | null;
  /** ⚠️ Mandatory on a rejection, and shown — see FR-028ح. */
  decision_reason: string | null;
  decided_at: string | null;
  created_at: string;
  to_cohort: { uuid: string; name: string } | null;
  from_cohort: { uuid: string; name: string } | null;
  student?: { uuid: string; name: string } | null;
}

/** One line of the history (FR-034). */
export interface CohortHistoryEvent {
  uuid: string;
  event:
    | "joined"
    | "transferred"
    | "left"
    | "removed"
    | "requested"
    | "approved"
    | "rejected"
    | "request_dropped";
  reason: string | null;
  created_at: string;
  cohort?: { uuid: string | null; name: string | null } | null;
  from_cohort?: { uuid: string | null; name: string | null } | null;
  student?: { uuid: string | null; name: string | null } | null;
  actor?: { uuid: string; name: string } | null;
}

export interface CohortOption {
  uuid: string;
  name: string;
  description: string | null;
  status: "open" | "closed" | "archived";
  /**
   * ⚠️ NULL IS NOT ZERO, AND IT IS NOT A NUMBER EITHER. Null means the group
   * declared no capacity — "unlimited" and "twenty free" are different promises
   * to a student choosing between two groups, and rendering null as `0` would
   * hide the only group they can actually join.
   */
  seats_left: number | null;
  is_full: boolean;
  /**
   * ⚠️ THE SERVER'S OWN PREDICATE, NEVER `status === "open" && !is_full` SPELLED
   * AGAIN HERE. The picker and the curriculum gate have to agree about what
   * «joinable» means — two spellings put one answer on the card and another at
   * the door, which is the defect `BookingEligibility` and `ListLeaderboardScopes`
   * have each already been fixed for.
   */
  is_joinable: boolean;
  members_count: number;
  /**
   * ⚠️ THE WHOLE REASON THE CHOICE SCREEN IS USABLE (FR-028أ). Choosing between
   * «المجموعة الأولى» and «المجموعة الثانية» is not choosing; the times are what
   * a student is actually picking between.
   */
  schedule_preview: string[];
}

export interface CohortsForCourse {
  membership: CohortMembership | null;
  pending_request: CohortTransferRequest | null;
  cohorts: CohortOption[];
}

/**
 * A classmate.
 *
 * ⚠️ AND NOTHING ELSE IS IN IT (FR-052). No attendance, no stay duration, no
 * grade, no teacher's note — those are `ATTENDANCE_VIEW` questions and this list
 * opens to every member of the group. A field added here is a field every
 * student reads about every classmate.
 */
export interface CohortMember {
  uuid: string;
  name: string;
  avatar_url: string | null;
  level: number;
  /**
   * ⚠️ ABSENT, NEVER ZERO, for a student who has no board row yet. Boards are
   * rolled up nightly, so a newcomer's rank does not exist — and «المركز ٠»
   * printed beside their name in front of the class is the reading of a zero.
   */
  rank?: number;
  badges: Array<{ key: string; name_ar: string; icon: string }>;
}

/** The refusal codes §ج answers a join or a transfer request with. */
export type CohortWriteRefusal =
  | "cohort_full"
  | "already_member"
  | "request_pending"
  | "same_cohort"
  | "cohort_closed";

export const cohorts = {
  forCourse: (courseUuid: string) =>
    api.get<CohortsForCourse>(`/courses/${courseUuid}/cohorts`),

  roster: (cohortUuid: string) =>
    api.get<{ members: CohortMember[] }>(`/cohorts/${cohortUuid}/roster`),

  join: (cohortUuid: string) =>
    api.post<CohortMembership>(`/cohorts/${cohortUuid}/join`),

  requestTransfer: (cohortUuid: string, reason?: string) =>
    api.post<CohortTransferRequest>(`/cohorts/${cohortUuid}/transfer-requests`, { reason }),

  withdrawRequest: (requestUuid: string) =>
    api.delete<void>(`/transfer-requests/${requestUuid}`),
};

/**
 * The teacher's half (§د).
 *
 * ⚠️ THERE IS NO `remove` FOR A GROUP, AND ITS ABSENCE IS THE REQUIREMENT
 * (FR-035). Archiving keeps every closed membership pointing at something that
 * resolves; a delete turns the whole history into rows naming a group that no
 * longer exists.
 */
export const manageCohorts = {
  list: (courseUuid: string) =>
    api.get<{ data: CohortOption[] }>(`/manage/courses/${courseUuid}/cohorts`),

  create: (courseUuid: string, body: { name: string; description?: string; capacity?: number | null }) =>
    api.post<CohortOption>(`/manage/courses/${courseUuid}/cohorts`, body),

  update: (cohortUuid: string, body: { name?: string; capacity?: number | null; status?: "open" | "closed" }) =>
    api.patch<CohortOption>(`/manage/cohorts/${cohortUuid}`, body),

  archive: (cohortUuid: string) => api.post<CohortOption>(`/manage/cohorts/${cohortUuid}/archive`),

  members: (cohortUuid: string) =>
    api.get<{ data: Array<{ uuid: string; name: string; joined_at: string }> }>(
      `/manage/cohorts/${cohortUuid}/members`,
    ),

  removeMember: (cohortUuid: string, studentUuid: string) =>
    api.delete<void>(`/manage/cohorts/${cohortUuid}/members/${studentUuid}`),

  history: (cohortUuid: string) =>
    api.get<{ data: CohortHistoryEvent[] }>(`/manage/cohorts/${cohortUuid}/history`),

  transferRequests: (courseUuid: string) =>
    api.get<{ data: CohortTransferRequest[] }>(`/manage/courses/${courseUuid}/transfer-requests`),

  approve: (requestUuid: string, reason?: string) =>
    api.post<CohortTransferRequest>(`/manage/transfer-requests/${requestUuid}/approve`, { reason }),

  /** ⚠️ `reason` is REQUIRED here — the student reads it (FR-028ح). */
  reject: (requestUuid: string, reason: string) =>
    api.post<CohortTransferRequest>(`/manage/transfer-requests/${requestUuid}/reject`, { reason }),

  unassignedSessions: (courseUuid: string) =>
    api.get<{
      data: Array<{ uuid: string; title: string; starts_at: string }>;
      meta: { total_hidden: number; assignable: number; already_held: number };
    }>(`/manage/courses/${courseUuid}/unassigned-sessions`),

  /**
   * ⚠️ ONE REQUEST FOR THE WHOLE BATCH, NEVER A LOOP HERE. Forty requests are
   * forty chances for one to fail in the middle, leaving half the timetable
   * hidden with nothing to say which half.
   */
  assignSessions: (courseUuid: string, cohortUuid: string, sessionUuids: string[]) =>
    api.post<{ assigned: number }>(`/manage/courses/${courseUuid}/assign-sessions`, {
      cohort_uuid: cohortUuid,
      session_uuids: sessionUuids,
    }),
};
