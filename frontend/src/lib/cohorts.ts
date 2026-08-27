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
  to_cohort_name: string;
  created_at: string;
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
