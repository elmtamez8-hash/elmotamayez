import { api } from "./api";

/**
 * Starting a payment, and reading how it ended.
 *
 * Mirrors `PaymentController` and `PaymentTransactionResource` field for field.
 *
 * ⚠️ NO PAYMENT INSTRUMENT PASSES THROUGH HERE — not a number, not a masked one,
 * not a token (FR-003). The platform hands the payer to the provider's own page
 * or gives them transfer instructions; a card never touches this code, and a
 * field for one is how it would start to.
 *
 * ⚠️ AND MONEY STAYS AN INTEGER until `formatMinorMoney` turns it into text. The
 * API sends `amount_minor` and a currency code precisely so the client never has
 * to parse a formatted string back before it can add anything up.
 */

export type PaymentStatus =
  | "initiated"
  | "pending"
  | "captured"
  | "failed"
  | "expired"
  | "mismatch"
  | "reversed";

export interface ChargeIntent {
  reference: string;
  method: "bank_transfer" | "mobile_wallet" | "gateway";
  method_label: string;
  /**
   * Where to send the payer — null when the method has no page at all. A bank
   * transfer is made in the payer's own bank, and a component that assumed a
   * redirect would send them nowhere.
   */
  redirect_url: string | null;
  /** What to tell them when there is no page: an account, a reference. */
  instructions: string | null;
  amount_minor: number;
  currency: string;
}

export interface PaymentTransaction {
  uuid: string;
  status: PaymentStatus;
  status_label: string;
  method: string | null;
  method_label: string | null;
  amount_minor: number;
  currency: string;
  /** A sentence the payer can act on — never the provider's raw error. */
  failure_reason: string | null;
  settled_at: string | null;
  created_at: string;
}

export function startPayment(orderUuid: string, provider?: string) {
  return api.post<ChargeIntent>(`/payments/${orderUuid}/charge`, provider ? { provider } : {});
}

export function fetchPayment(uuid: string) {
  return api.get<PaymentTransaction>(`/payments/${uuid}`);
}

/**
 * Whether this status is the end of the story.
 *
 * ⚠️ Used to decide whether to keep asking, NEVER to decide the outcome itself.
 * The return page displays; the server decides (FR-009). A client that concluded
 * "captured" because a redirect said so would be trusting the payer's browser
 * with the answer to whether they paid.
 */
export function isSettled(status: PaymentStatus): boolean {
  return status !== "initiated" && status !== "pending";
}
