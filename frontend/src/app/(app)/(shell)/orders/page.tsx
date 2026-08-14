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

const OPEN_STATUSES = ["pending", "under_review"];

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");
  /** uuid of the order whose rejection reason is being typed. */
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");
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

  const approve = async (uuid: string) => {
    setBusy(uuid);
    setError("");
    try {
      await api.post(`/orders/${uuid}/approve`);
      replace(uuid, { status: "approved" });
    } catch (err: unknown) {
      // Swallowing this left the row unchanged with no explanation, so the
      // approval looked like it had simply not registered.
      setError(errorMessage(err, "تعذّر اعتماد الطلب. أعد المحاولة."));
    } finally {
      setBusy(null);
    }
  };

  const reject = async (uuid: string) => {
    setBusy(uuid);
    setError("");
    try {
      // ⚠️ `reason`, NOT `rejection_reason`. The API has always validated
      // `reason` as required, so every rejection from this screen came back 422
      // and the row simply never changed — the field name was the bug, and the
      // "(اختياري)" placebo beside it was what made it look like a choice.
      await api.post(`/orders/${uuid}/reject`, { reason: reason.trim() });
      replace(uuid, { status: "rejected", rejection_reason: reason.trim() });
      setRejecting(null);
      setReason("");
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر رفض الطلب. أعد المحاولة."));
    } finally {
      setBusy(null);
    }
  };

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

  const canManage = orders.some((o) => OPEN_STATUSES.includes(o.status));

  const columns: Column<Order>[] = [
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
        </div>
      ),
    },
    {
      key: "receipt",
      header: "الإيصال",
      render: (o) =>
        o.receipt_url ? (
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

  if (canManage) {
    columns.push({
      key: "actions",
      header: "إجراءات",
      render: (o) =>
        OPEN_STATUSES.includes(o.status) ? (
          rejecting === o.uuid ? (
            // Inline, not window.prompt(): a native dialog blocks the page, is
            // untranslatable, and cannot be styled or tested.
            <div className="flex min-w-64 flex-col gap-2">
              <label htmlFor={`reason-${o.uuid}`} className="sr-only">
                سبب الرفض
              </label>
              <input
                id={`reason-${o.uuid}`}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="سبب الرفض"
                className="w-full rounded-lg border border-line bg-surface-raised px-3 py-1.5 text-xs text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="danger"
                  loading={busy === o.uuid}
                  disabled={reason.trim() === ""}
                  onClick={() => reject(o.uuid)}
                >
                  تأكيد الرفض
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setRejecting(null);
                    setReason("");
                  }}
                >
                  إلغاء
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex gap-2">
              <Button
                size="sm"
                loading={busy === o.uuid}
                onClick={() => approve(o.uuid)}
              >
                اعتماد
              </Button>
              <Button
                size="sm"
                variant="secondary"
                disabled={busy === o.uuid}
                onClick={() => {
                  setRejecting(o.uuid);
                  setReason("");
                }}
              >
                رفض
              </Button>
            </div>
          )
        ) : (
          <span className="text-xs text-ink-muted">—</span>
        ),
    });
  }

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
