import { api } from "./api";
import { counted } from "./labels";

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
/**
 * 036 -- the third case, and its absence kept `tsc` green over the whole leak.
 * A plan may cover one GROUP, which is the only coverage a plan sold by
 * sessions is allowed to carry.
 */
export type PlanCoverage = "workspace" | "course" | "cohort";
export type SessionType = "individual" | "group";
export type SubscriptionStatus = "active" | "expired" | "cancelled";

export interface Plan {
  uuid: string;
  title: string;
  /**
   * 036 -- NULLABLE NOW, and exactly one of this and `session_count` is set.
   * Typed `number`, every screen printing it was unreachable to `tsc` on the
   * day the column turned nullable -- and `null % 30 === 0` is true in
   * JavaScript, so the fallback printed the word `null` at a buyer.
   */
  duration_days: number | null;
  /** The other shape: a number of sessions, poured into 035's ledger. */
  session_count: number | null;
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
  /** One of the two, never both and never neither -- the Action refuses the rest. */
  duration_days?: number | null;
  session_count?: number | null;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  coverage_uuid?: string | null;
  is_active?: boolean;
}

/**
 * What a teacher may ask for on a plan the platform has already priced (036).
 *
 * ⚠️ `requested_price_minor` IS OPTIONAL, AND ITS ABSENCE IS «THE PLATFORM
 * DECIDES» RATHER THAN «FREE». Pricing is the platform's half of the row, so a
 * teacher asking for a new shape and leaving the number alone is the ordinary
 * case — and the approval then puts the new plan in the pricing queue.
 */
export interface PlanChangePayload {
  duration_days?: number | null;
  session_count?: number | null;
  session_type: SessionType;
  coverage_type: PlanCoverage;
  coverage_uuid?: string | null;
  requested_price_minor?: number | null;
  reason?: string | null;
}

export type PlanChangeStatus = "pending" | "approved" | "rejected";

/**
 * ⚠️ BOTH SIDES ARRIVE AS SENTENCES THE SERVER BUILT. «من حصّة واحدة إلى ١٢
 * حصّة» is the whole content of a row here, and deriving it in TypeScript would
 * be a second spelling of `planShape` — which is the defect spec 036 spent a
 * whole requirement on.
 */
export interface PlanChangeRequest {
  uuid: string;
  plan_title: string;
  current_shape: string | null;
  requested_shape: string | null;
  current_coverage_label: string;
  requested_coverage_label: string;
  current_price_minor: number | null;
  requested_price_minor: number | null;
  currency: string;
  reason: string | null;
  status: PlanChangeStatus;
  status_label: string;
  decision_reason: string | null;
  requested_at: string | null;
  decided_at: string | null;
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

    /**
     * ⛔ 036 — THE WAY THROUGH ONCE THE PLATFORM HAS PRICED A PLAN. `update`
     * refuses to move the shape or the coverage of a priced plan, because that
     * moves the thing the platform put a number on out from under the number.
     * This asks instead; an officer decides, and approval writes a NEW plan and
     * retires this one.
     */
    requestChange: (planUuid: string, payload: PlanChangePayload) =>
      api.post<{ data: PlanChangeRequest }>(
        `/manage/plans/${encodeURIComponent(planUuid)}/change-requests`,
        payload,
      ),

    changeRequests: () => api.get<{ data: PlanChangeRequest[] }>("/manage/plan-change-requests"),
  },
};

/**
 * «شهر» / «٣٠ يوماً» — a month is a marketing word, the column is days.
 *
 * ⛔ **IT PRINTED «null يوماً», AND THE TYPE SIGNATURE IS WHY THAT WAS INVISIBLE.**
 * `null % 30 === 0` is TRUE in JavaScript, so a null slipped past the month
 * branch's first condition and landed in the fallback as text: a plan advertised
 * to a buyer as «null يوماً · حصص جماعية». The parameter was typed `number`, so
 * `tsc` could never raise it — and a TypeScript type is a claim about the API,
 * not a guarantee from it, which this repository has paid for before (a
 * constrained eager load that answered 200 with a blank name).
 *
 * ⚠️ IT IS NOT REACHABLE TODAY AND THAT IS NOT WHY THE GUARD IS HERE.
 * `plans.duration_days` is `unsignedInteger` NOT NULL, so the API cannot send
 * one. Spec 036 makes a plan «by sessions OR by duration» — the day that column
 * turns nullable, every screen that prices a plan starts printing the word
 * `null` at a buyer, with nothing failing anywhere. The guard is cheap now and
 * unwritable later, after the first report.
 *
 * Returns `null` rather than «—» so a caller joining parts with « · » drops it
 * instead of printing a dash in a sentence; the one place that needs a visible
 * placeholder (a table cell under a «المدّة» header) supplies its own.
 */
export function planDuration(days: number | null | undefined): string | null {
  // `typeof` first, because it is the narrowing one — after it the rest of the
  // body sees a `number` and needs no cast. `isInteger` then refuses NaN,
  // Infinity and 30.5, and `<= 0` refuses the zero a half-filled row carries.
  if (typeof days !== "number" || !Number.isInteger(days) || days <= 0) return null;

  if (days % 30 === 0) {
    const months = days / 30;

    return months === 1 ? "شهر واحد" : months === 2 ? "شهران" : `${months} أشهر`;
  }

  return days === 1 ? "يوم واحد" : `${days} يوماً`;
}

/**
 * «١٢ حصّة» / «شهر واحد» — what this plan actually sells, in one sentence.
 *
 * ⛔ ONE SPELLING, FOUR SCREENS. `subscribe`, `manage/plans`, `plans` and
 * `orders` each printed the duration directly, so the day a plan could be sold
 * by SESSIONS all four would have printed «null يوماً» at a buyer — three of
 * them at the student who had already paid. Deriving «which shape is this» in
 * each of them is the two-spellings defect this tree has paid for a dozen times.
 *
 * ⚠️ AND THE COUNT GOES THROUGH `counted()`, NEVER A TEMPLATE LITERAL. Arabic
 * agrees the noun with its number across five bands, so «٢ حصص» is wrong where
 * «حصّتان» is right — and 12 and 30 are precisely the band where a hand-written
 * literal happens to agree, which is why the test for this uses neither.
 *
 * Returns `null` for a row carrying neither, so a caller joining parts with
 * « · » drops it instead of printing a dash inside a sentence.
 */
export function planShape(plan: Pick<Plan, "duration_days" | "session_count">): string | null {
  const sessions = plan.session_count;

  if (typeof sessions === "number" && Number.isInteger(sessions) && sessions > 0) {
    return counted(sessions, {
      one: "حصّة واحدة",
      two: "حصّتان",
      few: "حصص",
      many: "حصّة",
      other: "حصّة",
    });
  }

  return planDuration(plan.duration_days);
}

export const SESSION_TYPE_LABELS: Record<SessionType, string> = {
  individual: "حصص فردية",
  group: "حصص جماعية",
};
