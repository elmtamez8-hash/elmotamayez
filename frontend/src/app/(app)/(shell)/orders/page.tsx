"use client";

import { useCallback, useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Order } from "@/lib/types";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
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
      await api.post(`/orders/${uuid}/reject`, {
        rejection_reason: reason.trim() || undefined,
      });
      replace(uuid, { status: "rejected", rejection_reason: reason.trim() || null });
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
    { key: "status", header: "الحالة", render: (o) => <StatusBadge status={o.status} /> },
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
                placeholder="سبب الرفض (اختياري)"
                className="w-full rounded-lg border border-line bg-surface-raised px-3 py-1.5 text-xs text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="danger"
                  loading={busy === o.uuid}
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
