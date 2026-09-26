import { api } from "@/lib/api";

/**
 * The payments audit — the list of decisions, and the chain behind one payment.
 *
 * Both reads sit behind `billing.audit.view`, a platform permission. The list
 * page and the chain page share this file so the two cannot spell the labels,
 * or the shape of an entry, two ways.
 */

export type PaymentAuditEntry = {
  event: string;
  subject_type: string | null;
  subject_uuid: string | null;
  /**
   * The payment whose chain `/admin/payments/audit/{transaction}` walks — the
   * subject itself on a payment row, the order's settled payment on an order
   * row, and null wherever there is no payment to open.
   */
  payment_uuid: string | null;
  actor_name: string | null;
  ip_address: string | null;
  user_agent: string | null;
  properties: Record<string, unknown>;
  occurred_at: string | null;
};

export type PaymentAuditChain = {
  payment: {
    uuid: string;
    status: string;
    provider: string;
    method: string | null;
    settled_at: string | null;
  };
  order_uuid: string | null;
  credits_purchased: number | null;
  credits_remaining_in_lot: number | null;
  consumptions: { credits: number; entry_uuid: string | null; occurred_at: string | null }[];
  trail: PaymentAuditEntry[];
};

/**
 * Arabic for what was decided.
 *
 * A slug on the wire and a sentence here: the API is read by more than this page,
 * and a sentence written into an Action is a sentence that needs a deploy to
 * correct. Anything unmapped falls through as its slug rather than as a blank —
 * an audit row that renders empty is worse than one that renders ugly.
 */
export const EVENT_LABELS: Record<string, string> = {
  approved: "اعتماد دفعة",
  rejected: "رفض دفعة",
  "receipt.uploaded": "رفع إيصال",
  "credit_limit.changed": "تعديل الحد الائتماني",
  "exam_mode.opened": "فتح وضع الامتحانات",
  "exam_mode.closed": "إغلاق وضع الامتحانات",
  "payment.captured_surplus_unresolved": "دفعة زائدة تعذّر قيدها",
  "payment.initiated": "بدء عملية دفع",
  "payment.reversed": "عكس عملية دفع",
  "order.reversed": "إلغاء طلب واسترداده",
  "order.refund_settled": "تسوية استرداد",
};

export const SUBJECT_LABELS: Record<string, string> = {
  order: "طلب",
  payment: "عملية دفع",
  credit_entry: "قيد رصيد",
  balance: "حساب رصيد",
  package: "حزمة",
  exam_window: "نافذة امتحانات",
  consent: "موافقة شروط",
  session_unlock: "فتح محتوى حصة",
};

/** `PaymentStatus` — every case, so no status falls through as its slug. */
export const PAYMENT_STATUS_LABELS: Record<string, string> = {
  initiated: "بدأت",
  pending: "قيد الانتظار",
  captured: "مُحصَّلة",
  failed: "فشلت",
  expired: "انتهت مهلتها",
  mismatch: "مبلغ غير مطابق",
  reversed: "معكوسة",
};

/** `PaymentMethod`. */
export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  bank_transfer: "تحويل بنكي",
  mobile_wallet: "محفظة إلكترونية",
  gateway: "بوابة دفع",
};

export const eventLabel = (event: string) => EVENT_LABELS[event] ?? event;

export const subjectLabel = (slug: string | null) =>
  slug === null ? "—" : (SUBJECT_LABELS[slug] ?? slug);

export const paymentAudit = {
  list: () => api.get<{ data: PaymentAuditEntry[] }>("/admin/payments/audit"),
  chain: (transaction: string) =>
    api.get<{ data: PaymentAuditChain }>(
      `/admin/payments/audit/${encodeURIComponent(transaction)}`,
    ),
};
