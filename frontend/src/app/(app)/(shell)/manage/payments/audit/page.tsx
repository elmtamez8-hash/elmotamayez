"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import { Alert } from "@/components/ui/Alert";
import { Table, type Column } from "@/components/ui/Table";

type AuditEntry = {
  event: string;
  subject_type: string | null;
  subject_uuid: string | null;
  actor_name: string | null;
  ip_address: string | null;
  user_agent: string | null;
  properties: Record<string, unknown>;
  occurred_at: string | null;
};

/**
 * Arabic for what was decided.
 *
 * A slug on the wire and a sentence here: the API is read by more than this page,
 * and a sentence written into an Action is a sentence that needs a deploy to
 * correct. Anything unmapped falls through as its slug rather than as a blank —
 * an audit row that renders empty is worse than one that renders ugly.
 */
const EVENT_LABELS: Record<string, string> = {
  approved: "اعتماد دفعة",
  rejected: "رفض دفعة",
  "receipt.uploaded": "رفع إيصال",
  "credit_limit.changed": "تعديل الحد الائتماني",
  "exam_mode.opened": "فتح وضع الامتحانات",
  "exam_mode.closed": "إغلاق وضع الامتحانات",
  "payment.captured_surplus_unresolved": "دفعة زائدة تعذّر قيدها",
};

const SUBJECT_LABELS: Record<string, string> = {
  order: "طلب",
  payment: "عملية دفع",
  credit_entry: "قيد رصيد",
  balance: "حساب رصيد",
  package: "حزمة",
  exam_window: "نافذة امتحانات",
  consent: "موافقة شروط",
};

export default function PaymentAuditPage() {
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);
    setError("");

    api
      .get<{ data: AuditEntry[] }>("/admin/payments/audit")
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
      render: (e) => formatDateTime(e.occurred_at),
    },
    {
      key: "event",
      header: "القرار",
      render: (e) => EVENT_LABELS[e.event] ?? e.event,
    },
    {
      key: "subject",
      header: "على",
      render: (e) => (
        <span className="text-xs text-ink-muted">
          {e.subject_type === null ? "—" : (SUBJECT_LABELS[e.subject_type] ?? e.subject_type)}
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
  ];

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">سجلّ التدقيق المالي</h2>
        <p className="mt-1 text-sm text-ink-muted">
          كل قرار مالي: ماذا حدث، على ماذا، بيد من، ومن أي جهاز. السجلّ لا يُعدَّل ولا يُحذف.
        </p>
      </div>

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
