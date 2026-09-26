"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import {
  eventLabel,
  paymentAudit,
  subjectLabel,
  type PaymentAuditEntry as AuditEntry,
} from "@/lib/payment-audit";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";
import { Alert } from "@/components/ui/Alert";
import { Table, type Column } from "@/components/ui/Table";
import { PageHeader } from "@/components/ui/PageHeader";
import { OrdersIcon } from "@/components/icons";

export default function PaymentAuditPage() {
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const timeZone = useViewerTimeZone();

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);
    setError("");

    paymentAudit
      .list()
      .then((res) => setEntries(res.data ?? []))
      .catch((err: unknown) => {
        setFailed(true);
        setError(userMessage(err));
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const columns: Column<AuditEntry>[] = [
    {
      key: "occurred_at",
      header: "الوقت",
      render: (e) => formatDateTime(e.occurred_at, timeZone),
    },
    {
      key: "event",
      header: "القرار",
      render: (e) => eventLabel(e.event),
    },
    {
      key: "subject",
      header: "على",
      render: (e) => (
        <span className="text-xs text-ink-muted">
          {subjectLabel(e.subject_type)}
        </span>
      ),
    },
    {
      key: "actor",
      header: "المنفّذ",
      // Null when nobody did it: a sweep corrects a payment with no person behind
      // it, and writing a name there would put someone's name on a decision they
      // never took.
      render: (e) => e.actor_name ?? "النظام",
    },
    {
      key: "ip",
      header: "من",
      render: (e) => (
        // FR-024's half. An account is what a compromised session borrows; the
        // terminal is what shows four approvals arriving from one machine at
        // three in the morning.
        <span className="font-mono text-xs" dir="ltr">
          {e.ip_address ?? "—"}
        </span>
      ),
    },
    {
      key: "chain",
      header: "السلسلة",
      // The decisions worth opening are logged on the ORDER, and the chain is
      // addressed by the PAYMENT — so the server names the payment on both
      // kinds of row. Everything else has no payment behind it, and says so
      // rather than offering a link to a 404.
      render: (e) =>
        e.payment_uuid === null ? (
          <span className="text-xs text-ink-muted">—</span>
        ) : (
          <Link
            href={`/manage/payments/audit/${e.payment_uuid}`}
            className="text-primary-ink hover:underline"
          >
            تتبّع الدفعة
          </Link>
        ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={OrdersIcon}
        title="سجلّ التدقيق المالي"
        description="كل قرار مالي: ماذا حدث، على ماذا، بيد من، ومن أي جهاز. السجلّ لا يُعدَّل ولا يُحذف."
      />

      {error !== "" && (
        <Alert tone="danger" title="تعذّر عرض السجلّ">
          {error}
        </Alert>
      )}

      <Table
        columns={columns}
        rows={entries}
        // The whole row, as on the reconciliation page and for the same reason:
        // two identical events in the same second are two real decisions — which
        // is exactly what a bulk approval looks like — and a key built from the
        // fields present would collapse them into one.
        rowKey={(e) => JSON.stringify(e)}
        caption="القرارات المالية بترتيب زمني عكسي"
        state={loading ? "loading" : failed ? "error" : "ready"}
        onRetry={load}
        emptyTitle="لا قرارات مسجّلة بعد"
        emptyDescription="سيظهر هنا كل اعتماد ورفض وتسوية رصيد فور وقوعه."
      />
    </div>
  );
}
