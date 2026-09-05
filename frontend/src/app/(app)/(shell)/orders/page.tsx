"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, errorMessage } from "@/lib/api";
import type { Order } from "@/lib/types";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { Select } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";

/*
 * Statuses that still take a receipt.
 *
 * ⚠️ `rejected` IS ON THE LIST (027 · FR-032), AND `approved` IS NOT. A refusal
 * the payer cannot answer is a refusal they repeat — the same transfer, uploaded
 * again as a NEW order, losing the reason, the amount they were quoted and the
 * thread the officer was reading. An approved order is the opposite case: a
 * second image there replaces the document the approver actually read.
 */
const OPEN_STATUSES = ["pending", "under_review", "rejected"];

/*
 * ⚠️ THE BUYER'S SCREEN, AND THE APPROVE/REJECT BUTTONS LEFT IT ON 2026-09-03.
 *
 * Approving a course order writes the enrolment, the enrolment is taught, and
 * spec 014 pays the teacher for teaching it — so the payee was the one
 * witnessing that their own money had arrived. `payments.approve` moved to the
 * platform's finance officer, and the decision is made in `/admin` on the orders
 * screen, where the receipt is opened beside the amount.
 *
 * ⚠️ AND THE CONTROL WAS NEVER PERMISSION-GATED HERE AT ALL. It rendered on
 * `orders.some(o => OPEN_STATUSES.includes(o.status))` — "is any row still open"
 * — which is true of a STUDENT looking at their own unpaid order. So every
 * student was offered «اعتماد» on the payment they had just made, and learned
 * the product did not know who they were when the server refused it. Deriving an
 * audience in TypeScript is the two-spellings defect the comment about
 * `payer_name` below already refuses by name.
 */

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");
  /** How each payer says they paid, by order uuid. Bank transfer until told. */
  const [methods, setMethods] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Order[] }>("/orders")
      .then((res) => setOrders(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const replace = (uuid: string, patch: Partial<Order>) =>
    setOrders((prev) => prev.map((o) => (o.uuid === uuid ? { ...o, ...patch } : o)));

  const uploadReceipt = async (uuid: string, file: File) => {
    setBusy(uuid);
    setError("");
    try {
      const updated = await api.upload<Order>(`/orders/${uuid}/receipt`, (() => {
        const form = new FormData();
        form.append("receipt", file);
        // ⚠️ THE FIELD THE API HAS ACCEPTED SINCE THE GATEWAY LANDED, AND THAT
        // NOTHING ASKED FOR. It is optional on purpose — an older client must
        // not be refused — so every receipt arrived stamped "bank transfer",
        // including the wallet ones, and `mobile_wallet` was a value the product
        // could not produce. A column filled by nobody is not half an
        // implementation; it is one that reads as finished.
        form.append("method", methods[uuid] ?? "bank_transfer");
        return form;
      })());
      replace(uuid, updated);
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر رفع الإيصال."));
    } finally {
      setBusy(null);
    }
  };

  /*
    The payer's column exists only for staff, and the SERVER decides that: the
    key is absent from a buyer's own payload (`orders.view_all` gates it), so
    testing the data is the same question as testing the permission — with one
    answer instead of two. Re-deriving "am I staff?" in TypeScript is the
    two-spellings defect this repository keeps paying for.
  */
  const seesPayer = orders.some((o) => o.payer_name != null || o.payer_email != null);

  const columns: Column<Order>[] = [
    ...(seesPayer
      ? [
          {
            key: "payer",
            header: "الدافع",
            render: (o: Order) => (
              <div className="flex flex-col items-start">
                <span>{o.payer_name ?? "—"}</span>
                {o.payer_email && (
                  <span className="text-xs text-ink-muted">{o.payer_email}</span>
                )}
              </div>
            ),
          } satisfies Column<Order>,
        ]
      : []),
    { key: "course", header: "الكورس", render: (o) => o.course_title ?? "—" },
    {
      key: "amount_minor",
      header: "المبلغ",
      numeric: true,
      render: (o) => formatMinorMoney(o.amount_minor, o.currency),
    },
    {
      key: "status",
      header: "الحالة",
      render: (o) => (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge status={o.status} />
          {/* The promise, shown only while it is still owed (FR-023). A payer
              who uploaded a receipt and sees nothing but "قيد المراجعة" has no
              way to tell waiting from being forgotten. */}
          {o.review_sla_hours !== null && o.has_receipt && (
            <span className="text-xs text-ink-muted">
              تُراجَع خلال {o.review_sla_hours} ساعة
            </span>
          )}
          {/* ⚠️ THE REASON WAS ON THE PAYLOAD AND ON NO SCREEN. `rejection_reason`
              has been in the `Order` type all along and was rendered nowhere, so
              a payer read «مرفوض» and had to ask by message what was wrong with
              it — while the officer had typed the answer (FR-032). */}
          {o.status === "rejected" && o.rejection_reason !== null && (
            <span className="text-xs text-danger-ink">{o.rejection_reason}</span>
          )}
        </div>
      ),
    },
    {
      key: "receipt",
      header: "الإيصال",
      render: (o) =>
        // ⚠️ A REJECTED ORDER SHOWS THE UPLOAD, NOT THE OLD LINK. Its receipt
        // exists, so the view branch would win and the payer would be left
        // looking at the image that was refused with no way to replace it.
        o.receipt_url && !(o.is_mine && o.status === "rejected") ? (
          <a
            href={o.receipt_url}
            target="_blank"
            rel="noopener noreferrer"
            className="rounded text-xs font-medium text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            عرض الإيصال
          </a>
        ) : o.is_mine && OPEN_STATUSES.includes(o.status) ? (
          <div className="flex flex-col items-start gap-1.5">
            {/* Asked BEFORE the file, because the file picker is a one-way door:
                once it closes the upload is already on its way, and a method
                chosen afterwards would be a method chosen for the next receipt. */}
            <label htmlFor={`method-${o.uuid}`} className="sr-only">
              وسيلة الدفع
            </label>
            <Select
              id={`method-${o.uuid}`}
              value={methods[o.uuid] ?? "bank_transfer"}
              onChange={(e) => setMethods((prev) => ({ ...prev, [o.uuid]: e.target.value }))}
              disabled={busy === o.uuid}
              chevron="sm"
              className="rounded-lg border border-line bg-surface-raised px-2 py-1 text-xs text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
            >
              {/* No «بوابة دفع» here: a gateway payment leaves no receipt to
                  upload — it is «ادفع الآن» in the next column, and offering it
                  beside a file picker would invite a receipt for a payment the
                  platform already watched happen. */}
              <option value="bank_transfer">تحويل بنكي</option>
              <option value="mobile_wallet">محفظة إلكترونية</option>
            </Select>
            <label className="cursor-pointer rounded text-xs font-medium text-primary-ink underline-offset-4 hover:underline focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary">
              {busy === o.uuid ? "جارٍ الرفع…" : "ارفع الإيصال"}
              <input
                type="file"
                accept=".jpg,.jpeg,.png,.pdf"
                className="sr-only"
                disabled={busy === o.uuid}
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  e.target.value = "";
                  if (file) uploadReceipt(o.uuid, file);
                }}
              />
            </label>
          </div>
        ) : (
          <span className="text-xs text-ink-muted">—</span>
        ),
    },
    /*
     * ⚠️ THE ONLY WAY IN TO THE PAYMENT SCREEN, and without it that screen is
     * unreachable: /billing/pay needs an order uuid, and nothing else on the
     * platform hands one over. A page with no inbound link is a page nobody
     * visits, however correct it is.
     *
     * Shown for the payer's own open orders only. A settled order has nothing
     * left to pay, and someone else's is not theirs to settle.
     */
    {
      key: "pay",
      header: "السداد",
      render: (o) =>
        o.is_mine && OPEN_STATUSES.includes(o.status) ? (
          <Link
            href={`/billing/pay?order=${o.uuid}`}
            className="rounded text-xs font-semibold text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            ادفع الآن
          </Link>
        ) : (
          <span className="text-xs text-ink-muted">—</span>
        ),
    },
    { key: "date", header: "التاريخ", render: (o) => formatDate(o.created_at) },
  ];


  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">الطلبات</h2>

      {error && (
        <p role="alert" className="rounded-lg bg-danger/15 p-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <Table
        columns={columns}
        rows={orders}
        rowKey={(o) => o.uuid}
        caption="طلبات الشراء وحالتها وإيصالاتها"
        state={loading ? "loading" : failed ? "error" : "ready"}
        onRetry={load}
        emptyTitle="لا طلبات في سجلّك"
        emptyDescription="سيظهر هنا كل طلب شراء بحالته وإيصاله."
      />
    </div>
  );
}
