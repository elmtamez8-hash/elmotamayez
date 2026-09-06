import { api } from "./api";

/**
 * The teacher's settlement: their units, their rate, their statement.
 *
 * Types mirror `TeacherStatementResource` and `TeachingUnitResource` field for
 * field. Read the PHP resource before changing one — a type that claims a field
 * the API does not send renders a blank with no error anywhere.
 *
 * Note what is absent, and note that it is absent rather than filtered: there is
 * no field here for what a student paid, what the platform kept, or what the
 * sale price was. That separation is the whole point of the settlement context,
 * and `TeacherFieldAllowlist` fails the backend build if one appears.
 *
 * Amounts arrive as MINOR units beside their currency and are formatted here.
 * The server sends no pre-formatted money on purpose: a formatted string is a
 * number the client would have to parse back before it could add anything up.
 */

export type SettlementPeriodStatus = "open" | "closed" | "paid";

export type RateRequestStatus = "pending" | "approved" | "rejected";

export interface SettlementRate {
  uuid: string;
  session_type: "individual" | "group";
  session_type_label: string;
  amount_minor: number;
  currency: string;
  /** Null means "every subject" / "every grade" — the default most teachers keep. */
  subject_id: number | null;
  grade_level: string | null;
  effective_from: string;
}

export interface RateChangeRequest {
  uuid: string;
  session_type: "individual" | "group";
  session_type_label: string;
  status: RateRequestStatus;
  status_label: string;
  current_amount_minor: number | null;
  requested_amount_minor: number;
  currency: string;
  subject_id: number | null;
  grade_level: string | null;
  requested_at: string;
  decided_at: string | null;
  decision_reason: string | null;
}

export interface TeachingUnit {
  uuid: string;
  session_type: "individual" | "group";
  session_type_label: string;
  status: "pending_package" | "accrued" | "disputed" | "settled" | "reversed";
  status_label: string;
  basis: string;
  amount_minor: number;
  currency: string;
  frozen_seats: number;
  /** What the package is still missing. Null once nothing is. */
  pending_reason: string | null;
  recording_fault: boolean;
  delivered_at: string;
  accrued_at: string | null;
}

export interface StatementDeduction {
  type: string;
  type_label: string;
  reason: string | null;
  /** Always negative — the sign is the fact, enforced when the entry is written. */
  amount_minor: number;
}

export interface TeacherStatement {
  period: {
    /** Null until a close has created the row; the window is real either way. */
    uuid: string | null;
    starts_on: string;
    ends_on: string;
    status: SettlementPeriodStatus;
    status_label: string;
  };
  students_count: number;
  units: {
    pending_package: number;
    accrued: number;
    disputed: number;
    settled: number;
    reversed: number;
    by_type: Partial<Record<"individual" | "group", number>>;
  };
  rates: SettlementRate[];
  pending_rate_request: RateChangeRequest | null;
  currency: string;
  gross_minor: number;
  deductions: StatementDeduction[];
  net_minor: number;
  carried_in_minor: number;
  next_payout_on: string;
}

/** A window that has stopped moving. Every number on it is frozen, not derived. */
export interface SettlementPeriod {
  uuid: string;
  starts_on: string;
  ends_on: string;
  status: SettlementPeriodStatus;
  status_label: string;
  currency: string;
  units_count: number;
  gross_minor: number;
  deductions_minor: number;
  carried_in_minor: number;
  net_minor: number;
  carried_out_minor: number;
  closed_at: string | null;
}

interface Paginated<T> {
  data: T[];
  meta: { total: number; current_page: number; last_page: number };
}

/**
 * "150.5" ←→ 15050. التحويلُ بالنصِّ لا بالضربِ في مئة.
 *
 * ⚠️ `Math.round(parseFloat(v) * 100)` هو الجوابُ الواضحُ وهو طريقُ العشرةِ
 * ملّيماتٍ إلى راتبِ مدرّس: العشريُّ العائمُ لا يمثّلُ كلَّ كسرٍ، والمنصّةُ
 * تخزّنُ مالَ التسويةِ عدداً صحيحاً من وحداتٍ صغرى لهذا السببِ بعينِه.
 *
 * `null` يعني «ليس مبلغاً» — فارغٌ أو فيه أكثرُ من منزلتَينِ عشريّتَين.
 */
export function toMinorUnits(input: string): number | null {
  const trimmed = input.trim();

  if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;

  const [whole, fraction = ""] = trimmed.split(".");

  return Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
}

export const settlement = {
  statement: () => api.get<TeacherStatement>("/settlement/statement"),
  units: (page = 1) =>
    api.get<Paginated<TeachingUnit>>(`/settlement/units?page=${page}`),
  rates: () => api.get<SettlementRate[]>("/settlement/rates"),
  periods: (page = 1) =>
    api.get<Paginated<SettlementPeriod>>(`/settlement/periods?page=${page}`),
  rateRequests: () => api.get<Paginated<RateChangeRequest>>("/settlement/rate-requests"),
  requestRate: (body: {
    session_type: "individual" | "group";
    requested_amount_minor: number;
    subject_id?: number | null;
    grade_level?: string | null;
  }) => api.post<RateChangeRequest>("/settlement/rate-requests", body),

  /**
   * The same statement as a file.
   *
   * Fetched rather than linked: the token goes out as an `Authorization` header
   * and an anchor never sends one, so a plain link would 401 on the one screen
   * where a silent failure looks like a missing feature.
   */
  exportStatement: () =>
    api.download("/settlement/statement/export", "settlement-statement.csv"),
};
