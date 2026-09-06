import { api } from "@/lib/api";
import type { CohortSummary, CourseDetail } from "@/lib/public-api";
import type { Plan, SessionType } from "@/lib/plans";
import type { Order } from "@/lib/types";

/**
 * The one subscription screen's whole client (027 · FR-006).
 *
 * ⚠️ IT READS THE COURSE THROUGH `api`, NOT THROUGH `publicApi`. That module
 * talks to `MARKETPLACE_API_URL` from the SERVER and caches for a minute; this
 * screen runs in the browser, behind the Next rewrite, and needs the group's
 * seats as they stand this second — a subscriber choosing a group that filled
 * forty seconds ago is a refusal they cannot act on.
 *
 * ⚠️ AND THE RECEIPT GOES TO THE EXISTING ORDER ROUTE. There is deliberately no
 * second upload path: `POST /orders/{uuid}/receipt` is the one place that records
 * the method, the IP and the user agent together, and a second one loses one of
 * the three in silence.
 */
export type SubscriptionMode = "cohort" | "private";

/*
 * ⚠️ THE INTENT LIVES ON `Order` NOW, AND IS NOT REDECLARED HERE. Every order
 * list renders it — the buyer's «الطلبات» and the officer's queue — so a second
 * copy of the shape beside this one is the two-spellings defect in TypeScript:
 * it stays green while the two drift, and the field simply stops appearing.
 */
export type SubscriptionOrder = Order;

/** The session type a mode may buy — the display filter behind FR-008. */
export function sessionTypeFor(mode: SubscriptionMode): SessionType {
  return mode === "cohort" ? "group" : "individual";
}

export const subscribe = {
  /**
   * The public course, read live.
   *
   * Used for the teacher's name, the group's name and its schedule — the three
   * things the screen has to show back so the buyer can see what they are paying
   * for (FR-006).
   */
  course: (uuid: string) =>
    api.get<{ data: CourseDetail }>(`/marketplace/courses/${encodeURIComponent(uuid)}`),

  /**
   * The plans that match the chosen mode.
   *
   * ⚠️ DISPLAY, NOT PROTECTION. The server refuses a mismatch on the POST below;
   * this only keeps a one-to-one price off a group choice. A rule enforced by a
   * dropdown is not enforced.
   */
  plans: (courseUuid: string, sessionType: SessionType) =>
    api.get<{ data: Plan[] }>(
      `/billing/plans?course=${encodeURIComponent(courseUuid)}&session_type=${sessionType}`,
    ),

  /** Creates the PENDING order. No access is granted until an officer approves. */
  create: (body: { plan_uuid: string; mode: SubscriptionMode; cohort_uuid?: string }) =>
    api.post<{ data: SubscriptionOrder }>("/billing/subscriptions", body),

  uploadReceipt: (orderUuid: string, file: File, method = "bank_transfer") => {
    const form = new FormData();

    form.append("receipt", file);
    form.append("method", method);

    return api.upload<Order>(`/orders/${encodeURIComponent(orderUuid)}/receipt`, form);
  },
};

/** The chosen group out of a course payload, or null for a private subscription. */
export function chosenCohort(
  course: CourseDetail | null,
  cohortUuid: string | null,
): CohortSummary | null {
  if (course === null || cohortUuid === null) return null;

  return course.cohorts.find((cohort) => cohort.uuid === cohortUuid) ?? null;
}
