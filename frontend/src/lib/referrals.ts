import { api } from "./api";

/**
 * Invitations (spec 011 · US3).
 *
 * ⚠️ THE INVITED PERSON IS NOT NAMED, AND THE TYPE SAYS SO BY OMISSION.
 * `ReferralResource` deliberately sends no name and no email: an inviter already
 * knows who they invited, and a list assembled out of other people's signups is
 * a contact list nobody consented to. A field added here that the API does not
 * send renders a blank with no error anywhere.
 *
 * ⚠️ AND `flagged_reason` IS ABSENT ON PURPOSE. It is written for a human
 * reviewing abuse; handing the suspected party the exact rule they tripped is a
 * tuning guide for the next attempt.
 */
export type ReferralStatus = "pending" | "completed" | "flagged" | "reversed";

export interface Referral {
  uuid: string;
  status: ReferralStatus;
  status_label: string;
  invited_at: string | null;
  completed_at: string | null;
}

export interface ReferralCode {
  code: string;
  completed_count: number;
}

// Declared locally, as `store.ts` and `bank.ts` each do: the shape is the API's
// paginator and there is no shared export for it.
type Paginated<T> = { data: T[]; meta?: { total: number; current_page: number; last_page: number } };

export const referrals = {
  /**
   * ⚠️ A `GET` THAT MINTS ON FIRST CALL. Every account that predates this
   * feature gets its code the moment somebody opens the page, rather than
   * needing a backfill over the whole users table — and the Action behind it is
   * built for two tabs arriving at once.
   */
  code: () => api.get<ReferralCode>("/referrals/code"),

  list: () => api.get<Paginated<Referral>>("/referrals"),
};
