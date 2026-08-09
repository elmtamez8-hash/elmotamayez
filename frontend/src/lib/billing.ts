import { api } from "./api";

/**
 * The student's credits — their balance per course and their ledger.
 *
 * Types mirror `CreditBalanceResource` and `CreditTransactionResource` field for
 * field. Read the PHP resource before changing one: a type that claims a field
 * the API does not send renders a blank with no error anywhere.
 *
 * ⚠️ NOT ONE FIGURE OF MONEY APPEARS HERE, and it is absent rather than hidden.
 * A credit's price is the teacher's approved settlement rate plus two platform
 * constants, so a student shown what they paid could solve for the constants
 * across two package sizes and then read every other teacher's rate off any
 * published total. There is no amount to format because the API sends none.
 *
 * Balances are listed PER COURSE and never summed. +10 in maths and −6 in
 * physics reads as +4 and unblocked when added up, while withholding is decided
 * per course precisely so the paid-up course stays open — the total is a wrong
 * answer, not a shorter one.
 */

export type CreditTransactionType =
  | "purchase"
  | "consume"
  | "bonus"
  | "refund"
  | "adjustment"
  | "expire";

export interface CreditBalance {
  uuid: string;
  course: {
    uuid: string;
    title: string;
    /** The academy this course belongs to — the name the student knows it by. */
    teacher_name: string;
  };
  purchased_credits: number;
  consumed_credits: number;
  /** Signed: a deferring mode lets this fall below zero, down to the limit. */
  remaining_credits: number;
  /** How far below zero this balance may go. Zero in prepaid mode. */
  credit_limit_credits: number;
  /** Derived server-side from the balance, the limit, the mode and exam mode. */
  is_withheld: boolean;
}

export interface CreditTransaction {
  uuid: string;
  type: CreditTransactionType;
  type_label: string;
  /** Signed: positive added credits, negative removed them. */
  credits: number;
  /** Mandatory on a bonus or a correction, absent on a purchase. */
  reason: string | null;
  created_at: string | null;
}

interface Paginated<T> {
  data: T[];
  meta: { total: number; current_page: number; last_page: number };
}

/**
 * A policy option, with the reason it cannot be picked rather than its absence.
 *
 * An option that is silently missing reads as a product that does not support
 * it; one shown greyed out with "not configured yet" reads as a thing that is
 * coming — which is the true statement (FR-015).
 */
export interface BillingOption {
  value: string;
  label: string;
  unavailable_reason: string | null;
}

export interface BillingCadenceOption extends BillingOption {
  sessions_per_cycle: number;
}

export interface BillingSettings {
  /** How money arrives, and whether a debt is allowed at all. */
  mode: string;
  mode_label: string;
  /** How much is settled at once — a session, half a month, a month. */
  cadence: string;
  cadence_label: string;
  allows_deferral: boolean;
  zero_balance_behavior: "block" | "remind" | "both";
  alert_thresholds: number[];
  /**
   * How large a ceiling this cadence justifies — ONE OF TWO factors, not the
   * ceiling itself. A student with no recorded consent to deferred payment
   * starts at zero however generous the cadence is (Q-9, FR-048), so the real
   * ceiling is `consent ? min(this, platform max) : 0`. Nothing applies it yet.
   */
  cadence_allows_credits: number;
  modes: BillingOption[];
  cadences: BillingCadenceOption[];
}

export interface BillingSettingsPatch {
  mode?: string;
  cadence?: string;
  zero_balance_behavior?: string;
  alert_thresholds?: number[];
}

/**
 * A package priced for one course — ONE TOTAL, never its parts.
 *
 * ⚠️ There is no breakdown field to render because the API sends none. The
 * total is the teacher's approved rate plus two platform constants, so a client
 * that displayed the components would publish what every teacher is paid to
 * anyone who opened it (FR-021ج).
 */
export interface CreditPackageOffer {
  uuid: string;
  name: string;
  credits: number;
  session_type: "individual" | "group";
  /** Null means the credits never expire — the launch policy. */
  validity_days: number | null;
  /** Minor units. The API never sends formatted money; see formatMinorMoney. */
  total_minor: number;
  currency: string;
}

export interface PurchaseStarted {
  /** The ORDER, which is what the receipt is uploaded against. */
  order: string;
  credits: number;
  total_minor: number;
  currency: string;
}

/*
 * ⚠️ EVERY LIST ENDPOINT IS TYPED `{ data: T[] }`, NEVER `T[]`.
 *
 * The API disables resource wrapping, so a collection route answers with a bare
 * array — and `lib/api.ts` re-wraps that array into `{ data: [...] }` so the
 * pages see one shape whatever the route did. Typing the client as `T[]` is
 * therefore a lie the compiler cannot catch: the value arrives as an object, the
 * page's `rows ?? []` guard passes it straight through because it is not
 * nullish, `rows.length === 0` is false because objects have no length, and the
 * component crashes on `.map is not a function` — in the browser, at runtime,
 * with a green build behind it.
 */
export const billing = {
  balances: () => api.get<{ data: CreditBalance[] }>("/billing/balance"),
  /*
   * An empty list is a real answer, not an error: a course whose teacher has no
   * approved rate cannot be priced, and one that has stopped delivering sessions
   * stops selling (FR-021ز · FR-021ط). Both render the same "not available"
   * state, because from the student's side they are the same fact.
   */
  packages: (courseUuid: string) =>
    api.get<{ data: CreditPackageOffer[] }>(
      `/billing/packages?course=${encodeURIComponent(courseUuid)}`,
    ),
  purchase: (courseUuid: string, packageUuid: string) =>
    api.post<PurchaseStarted>("/billing/purchases", {
      course: courseUuid,
      package: packageUuid,
    }),
  settings: () => api.get<BillingSettings>("/manage/billing/settings"),
  // PATCH, not PUT: a request carrying one key changes one thing, so saving the
  // mode cannot silently reset thresholds nobody looked at.
  updateSettings: (patch: BillingSettingsPatch) =>
    api.patch<BillingSettings>("/manage/billing/settings", patch),
  transactions: (courseUuid?: string, page = 1) =>
    api.get<Paginated<CreditTransaction>>(
      `/billing/transactions?page=${page}` +
        (courseUuid ? `&course=${encodeURIComponent(courseUuid)}` : ""),
    ),
};

/**
 * The credit count as text, with its sign carried by a word rather than a glyph.
 *
 * A bare `-3` in an RTL paragraph renders with the minus on whichever side the
 * bidirectional algorithm decides, and "3-" reads as nothing at all. Arabic
 * before the number is unambiguous in either direction.
 */
export function formatCredits(credits: number): string {
  const magnitude = Math.abs(credits).toLocaleString("ar-EG");

  if (credits < 0) return `${magnitude} تحت الصفر`;

  return magnitude;
}
