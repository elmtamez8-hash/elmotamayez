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
  /**
   * Sessions to buy before booking resumes — zero when nothing is withheld.
   *
   * Sent rather than subtracted here: the deficit is measured against the
   * EFFECTIVE floor, which also depends on the billing mode, an open exam window
   * and a current terms consent. None of those is in this payload, so the number
   * cannot be computed correctly in the browser — it can only look correct.
   */
  credits_needed: number;
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
/**
 * One name in the «who am I paying for» picker (031 · FR-012 · FR-018).
 *
 * ⚠️ TWO FIELDS, AND THE SERVER DECIDES WHICH NAMES APPEAR. `/billing/beneficiaries`
 * is `childrenOf(caller, payments)` literally — the same call the purchase is
 * proved against — so an option offered here cannot be refused at the door.
 * Filtering `/family/relations` in TypeScript is what this replaces, and it had
 * already produced two different answers in two files: `ChildSwitcher` narrows by
 * `status` and `student_uuid` with **no permission filter at all**, while the
 * subscription screen does filter by permission. Neither is the server's.
 */
export interface PurchaseBeneficiary {
  uuid: string;
  name: string;
}

/**
 * One option in the course picker — and the list is the server's, not a
 * narrowing of `/enrollments`.
 *
 * `isPartyTo` has three arms and an enrolment list covers one: a child enrolled
 * in ONE course at a teacher may have credits bought on ANY of that teacher's
 * courses. A picker built from enrolments hides those, silently, from the person
 * who came to buy them.
 */
export interface PurchasableCourse {
  uuid: string;
  title: string;
  /** The academy — what a student calls their teacher, and what tells two «الفيزياء ٣» apart. */
  teacher_name: string | null;
  cover_url: string | null;
}

/** One row of the teacher's panel: who, which course, how many sessions left. */
export interface StudentBalanceRow {
  student_uuid: string;
  student_name: string;
  course_uuid: string;
  course_title: string;
  remaining_credits: number;
  purchased_credits: number;
  consumed_credits: number;
  credit_limit_credits: number;
  is_withheld: boolean;
}

/**
 * The exam-mode window in force, if any (FR-046).
 *
 * Dates, not timestamps: it is a period on a calendar, and the day it ends is
 * included. There is no `is_open` field because there is no stored flag — the
 * absence of a window IS the off state.
 */
export interface ExamModeWindow {
  uuid: string;
  starts_on: string;
  ends_on: string;
}

/**
 * A document this person has been asked to accept, and whether they have.
 *
 * `consented_at` is null for both "never accepted" and "accepted a version that
 * has since been superseded" (FR-049) — one field, because to the person facing
 * the screen they are the same fact: this text is outstanding.
 *
 * ⚠️ THE VERSION IS READ, NEVER SENT. The server stamps the one in force at the
 * moment of signing; a client that named its own could accept superseded terms
 * for ever.
 */
export interface ConsentState {
  document: "deferred_payment_terms" | "data_processing";
  label: string;
  version: string;
  consented_at: string | null;
}

/**
 * One thing the nightly credit reconciliation could not make add up.
 *
 * The three checks emit different subjects — a balance, or a class session — so
 * the id fields are optional and the screen renders whichever arrived. No name
 * and no money: these are internal row ids and credit COUNTS, the same
 * restriction the payments sweep's screen keeps beside it.
 */
export type CreditReconciliationFinding = {
  check: "ledger_sum" | "lot_remainder" | "session_seats";
  workspace_id: number;
  credit_balance_id?: number;
  student_user_id?: number;
  class_session_id?: number;
  expected: number;
  actual: number;
};

export type CreditReconciliationRun = {
  ran_at: string;
  balances_checked: number;
  sessions_checked: number;
  /** The true total. `findings` is a sample capped at 200 by the job. */
  findings_count: number;
  findings: CreditReconciliationFinding[];
};

export const billing = {
  balances: () => api.get<{ data: CreditBalance[] }>("/billing/balance"),
  /*
   * What the nightly sweep found — `null` when it has never run at all, which is
   * a different answer from "it ran and found nothing" and must stay one.
   */
  creditReconciliation: () =>
    api.get<{ data: CreditReconciliationRun | null }>("/admin/billing/reconciliation"),
  /*
   * Agreeing to owe (FR-048). The whole list on both verbs, so the screen
   * re-renders from the response of the signature instead of asking again.
   */
  consents: () => api.get<{ data: ConsentState[] }>("/billing/consents"),
  accept: (document: ConsentState["document"]) =>
    api.post<{ data: ConsentState[] }>("/billing/consents", { document }),
  /*
   * An empty list is a real answer, not an error: a course whose teacher has no
   * approved rate cannot be priced, and one that has stopped delivering sessions
   * stops selling (FR-021ز · FR-021ط). Both render the same "not available"
   * state, because from the student's side they are the same fact.
   */
  packages: (courseUuid: string, studentUuid?: string) =>
    api.get<{ data: CreditPackageOffer[] }>(
      `/billing/packages?course=${encodeURIComponent(courseUuid)}`
      + (studentUuid === undefined ? "" : `&student_uuid=${encodeURIComponent(studentUuid)}`),
    ),
  purchase: (courseUuid: string, packageUuid: string, studentUuid?: string) =>
    api.post<PurchaseStarted>("/billing/purchases", {
      course: courseUuid,
      package: packageUuid,
      /*
       * ⚠️ `student_uuid`, NEVER `student` — the name spec 029 shipped for the
       * same field on the subscription door, resolved by the same class on the
       * server. Two names for one thing in one module is where a divergence
       * starts, and the field is OMITTED rather than sent null when nobody is
       * named: absent means «me», which is what every call meant before 031.
       */
      ...(studentUuid === undefined ? {} : { student_uuid: studentUuid }),
    }),
  /*
   * The picker's two sources (031). Both are server doors precisely because the
   * alternative — narrowing `/family/relations` and `/enrollments` in the
   * browser — is a second answer to a question the server already answers, and
   * FR-018 forbids it by name.
   *
   * ⚠️ `purchasableCourses` is PAGINATED and the envelope is real: `/enrollments`
   * once dropped it and all four of its readers showed zero, because `res.data`
   * on a bare array is `undefined`.
   */
  beneficiaries: () => api.get<{ data: PurchaseBeneficiary[] }>("/billing/beneficiaries"),
  purchasableCourses: (studentUuid?: string) =>
    api.get<{ data: PurchasableCourse[] }>(
      "/billing/purchasable-courses"
      + (studentUuid === undefined ? "" : `?student_uuid=${encodeURIComponent(studentUuid)}`),
    ),
  /*
   * A guardian's read of one child's balances. Its own call, not a parameter on
   * `balances()`: this one has to prove the relation AND the payments consent,
   * and folding them together would make the student's own request answer a
   * question it should never have to.
   */
  childBalances: (studentUuid: string) =>
    api.get<{ data: CreditBalance[] }>(
      `/billing/children/balance?student=${encodeURIComponent(studentUuid)}`,
    ),
  /*
   * The teacher's panel. Credits and withheld state, and no money at all — the
   * total a student paid is the platform's price, and a teacher who could read
   * it would solve for the platform's margin from any two rows.
   */
  students: () => api.get<{ data: StudentBalanceRow[] }>("/manage/billing/students"),
  /*
   * The exception FR-038 allows, with the record FR-039 requires.
   *
   * ⚠️ PER BALANCE, SO THE COURSE IS PART OF THE CALL. A ceiling on «the
   * student» would be a ceiling at every teacher they study with at once;
   * the balance sits on the course precisely so a paid-up course stays
   * open while another is withheld.
   *
   * The reason is required by the server and by the Action beneath it — a
   * nullable reason column is one caller away from an audit trail of blanks.
   */
  setCreditLimit: (studentUuid: string, body: { course: string; credit_limit_credits: number; reason: string }) =>
    api.patch<{ data: { credit_limit_credits: number } }>(
      `/manage/billing/students/${studentUuid}/limit`,
      body,
    ),
  /*
   * Exam mode: the window in which nothing is deferred (FR-046).
   *
   * `data` is null when none is in force, which is the whole state — there is no
   * stored on/off flag anywhere. A window covers today or it does not, so the
   * mode returns by itself the day after the last one with nothing to run.
   *
   * `close()` carries no uuid on purpose: the question is "turn it off", and
   * closing one row would leave an overlapping second window quietly in force
   * behind a screen showing it as off.
   */
  examMode: () => api.get<{ data: ExamModeWindow | null }>("/manage/billing/exam-mode"),
  openExamMode: (startsOn: string, endsOn: string) =>
    api.post<{ data: ExamModeWindow }>("/manage/billing/exam-mode", {
      starts_on: startsOn,
      ends_on: endsOn,
    }),
  closeExamMode: () => api.delete<{ data: null }>("/manage/billing/exam-mode"),
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
