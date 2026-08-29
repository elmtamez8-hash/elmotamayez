import { api } from "./api";

/**
 * Subscription plans and the subscriptions bought from them (spec 011 · US4).
 *
 * ⚠️ THIS IS ONE OF THREE PRICING SHAPES, AND THE ONLY ONE THAT SELLS TIME.
 * A teacher's price changes with the subject, the year and the size of the room,
 * so what a student is offered comes in three forms:
 *
 *   · «بالحصّة»        — a `CreditPackageOffer` of one credit  (`billing.packages`)
 *   · «بعدد من الحصص»  — a `CreditPackageOffer` of N credits   (`billing.packages`)
 *   · «بالشهر»         — a `Plan`, here
 *
 * They stay two calls on purpose: a credit package's total is derived from the
 * teacher's approved settlement rate and is therefore guarded by participation
 * in the course, while a plan's price is a flat number a platform officer typed
 * and derives from nothing. Merging them would put the stricter guard on both or
 * the looser guard on both.
 *
 * ⚠️ MONEY ARRIVES IN MINOR UNITS AND IS NEVER PRE-FORMATTED. `formatMinorMoney`
 * is the only place it becomes text — a formatted string is a number the client
 * has to parse back before it can add anything up.
 */
export type PlanCoverage = "workspace" | "course";
export type SessionType = "individual" | "group";
export type SubscriptionStatus = "active" | "expired" | "cancelled";

export interface Plan {
  uuid: string;
  title: string;
  duration_days: number;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  coverage_label: string;
  coverage_uuid: string | null;
  /**
   * ⚠️ `null` MEANS UNPRICED AND NEVER FREE. The platform sets the price
   * (FR-025 · Q4), so between a teacher creating a plan and an officer pricing it
   * this is null — and `(int) null === 0` on the server, which is why the
   * catalogue filters those out rather than letting one be bought for nothing.
   * The teacher's own list keeps them, because «تنتظر تسعير المنصّة» is the whole
   * reason nobody can buy it.
   */
  price_minor: number | null;
  currency: string;
  is_active: boolean;
  is_sellable: boolean;
}

export interface Subscription {
  uuid: string;
  plan_title: string | null;
  teacher_name: string | null;
  starts_on: string;
  /** The EFFECTIVE end — a freeze may have moved it past what the plan sold. */
  ends_on: string;
  /** Only present when a freeze actually extended it; null otherwise. */
  sold_ends_on: string | null;
  status: SubscriptionStatus;
  status_label: string;
  /** Absent for a reader who is neither the payer nor the platform. */
  price_minor?: number;
  currency?: string;
}

export interface SavePlanPayload {
  title: string;
  duration_days: number;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  coverage_uuid?: string | null;
  is_active?: boolean;
}

export const plans = {
  /**
   * What the teacher behind this course sells.
   *
   * The COURSE identifies the teacher, because it is the uuid a student already
   * holds — no student-facing payload carries a workspace uuid, and adding one
   * would be the tenant key travelling.
   */
  forCourse: (courseUuid: string) =>
    api.get<{ data: Plan[] }>(`/billing/plans?course=${encodeURIComponent(courseUuid)}`),

  /** The subscriptions this student holds — live ones and finished ones. */
  mine: () => api.get<{ data: Subscription[] }>("/billing/subscriptions"),

  /**
   * ⚠️ THE ANSWER IS AN ORDER, NOT A SUBSCRIPTION. A manual bank transfer takes
   * days, so nothing is opened until the money is witnessed — the screen has to
   * say «ستبدأ باقتك عند اعتماد الدفعة» rather than showing an active month.
   */
  buy: (planUuid: string) =>
    api.post<{ data: { uuid: string; amount_minor: number; currency: string } }>(
      "/billing/subscriptions",
      { plan_uuid: planUuid },
    ),

  /** The teacher's own plans — including the ones awaiting a price. */
  manage: {
    list: () => api.get<{ data: Plan[] }>("/manage/plans"),
    create: (payload: SavePlanPayload) => api.post<{ data: Plan }>("/manage/plans", payload),
    update: (uuid: string, payload: SavePlanPayload) =>
      api.patch<{ data: Plan }>(`/manage/plans/${uuid}`, payload),
  },
};

/** «شهر» / «٣٠ يوماً» — a month is a marketing word, the column is days. */
export function planDuration(days: number): string {
  if (days % 30 === 0 && days >= 30) {
    const months = days / 30;

    return months === 1 ? "شهر واحد" : months === 2 ? "شهران" : `${months} أشهر`;
  }

  return days === 1 ? "يوم واحد" : `${days} يوماً`;
}

export const SESSION_TYPE_LABELS: Record<SessionType, string> = {
  individual: "حصص فردية",
  group: "حصص جماعية",
};
