"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDateTime, formatMinorMoney } from "@/lib/labels";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";

type Bucket = {
  key: string | null;
  currency: string;
  transactions: number;
  amount_minor: number;
};

type Summary = {
  from: string;
  to: string;
  by_method: Bucket[];
  by_status: Bucket[];
  by_source: Bucket[];
  totals: Bucket[];
};

type Row = {
  uuid: string;
  occurred_at: string | null;
  status: string;
  method: string | null;
  amount_minor: number;
  currency: string;
  source: string | null;
  student_name: string | null;
  pricing: { credits: number; total_minor: number } | null;
};

/**
 * Arabic for each dimension's keys.
 *
 * Slugs on the wire and sentences here, as on the reconciliation and audit
 * screens: the API is read by more than this page, and a label written into a
 * query is a label that needs a deploy to correct. Anything unmapped falls
 * through as its slug — an ugly row beats a blank one.
 */
const METHOD_LABELS: Record<string, string> = {
  bank_transfer: "تحويل بنكي",
  mobile_wallet: "محفظة إلكترونية",
  gateway: "بوابة دفع",
};

const STATUS_LABELS: Record<string, string> = {
  initiated: "بُدئت",
  pending: "قيد الانتظار",
  captured: "محصَّلة",
  failed: "فشلت",
  expired: "انتهت مهلتها",
  mismatch: "مبلغ مخالف",
  reversed: "معكوسة",
};

const SOURCE_LABELS: Record<string, string> = {
  course: "شراء كورس",
  credits: "شراء أرصدة",
};

const DIMENSIONS = [
  { field: "by_method", title: "بالطريقة", labels: METHOD_LABELS },
  { field: "by_status", title: "بالحالة", labels: STATUS_LABELS },
  { field: "by_source", title: "بالمصدر", labels: SOURCE_LABELS },
] as const;

/** `YYYY-MM-DD`, which is what both the input and the API want. */
function isoDay(daysAgo: number): string {
  const at = new Date();
  at.setDate(at.getDate() - daysAgo);

  return at.toISOString().slice(0, 10);
}

export default function CollectionReportPage() {
  const [from, setFrom] = useState(isoDay(30));
  const [to, setTo] = useState(isoDay(0));
  const [method, setMethod] = useState("");
  const [status, setStatus] = useState("");

  const [summary, setSummary] = useState<Summary | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");

  // The filter as the API takes it, built in one place so the screen and the
  // export cannot drift — the same reason the backend gives them one Request.
  const query = useCallback(() => {
    const params = new URLSearchParams({ from, to });

    if (method !== "") params.set("method", method);
    if (status !== "") params.set("status", status);

    return params.toString();
  }, [from, to, method, status]);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);
    setError("");

    api
      .get<{ data: Summary; rows: { data: Row[] } }>(`/admin/payments/collection?${query()}`)
      .then((res) => {
        setSummary(res.data);
        setRows(res.rows?.data ?? []);
      })
      // Never raw: a 403 here is a person who may not read the platform's
      // collection, and a stack of JSON tells them nothing they can act on.
      .catch((err: unknown) => {
        setFailed(true);
        setError(userMessage(err));
      })
      .finally(() => setLoading(false));
  }, [query]);

  // On mount only. Afterwards the reader presses «اعرض» — a report that
  // refetched on every keystroke of a date field would send four requests for
  // one period, three of them for periods nobody asked about.
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const exportFile = () => {
    setError("");

    api
      .download(`/admin/payments/collection/export?${query()}`, `collection-${from}-${to}.csv`)
      .catch((err: unknown) => setError(userMessage(err)));
  };

  const columns: Column<Row>[] = [
    {
      key: "occurred_at",
      header: "الوقت",
      render: (r) => formatDateTime(r.occurred_at),
    },
    { key: "student", header: "الطالب", render: (r) => r.student_name ?? "—" },
    {
      key: "source",
      header: "المصدر",
      render: (r) => (r.source === null ? "—" : (SOURCE_LABELS[r.source] ?? r.source)),
    },
    {
      key: "method",
      header: "الطريقة",
      render: (r) => (r.method === null ? "—" : (METHOD_LABELS[r.method] ?? r.method)),
    },
    { key: "status", header: "الحالة", render: (r) => STATUS_LABELS[r.status] ?? r.status },
    {
      key: "credits",
      header: "الأرصدة",
      numeric: true,
      render: (r) => (r.pricing === null ? "—" : r.pricing.credits),
    },
    {
      key: "amount",
      header: "المبلغ",
      numeric: true,
      // The only place a number becomes currency, here as everywhere: the API
      // never sends money as text, so the client can add it up before it prints.
      render: (r) => formatMinorMoney(r.amount_minor, r.currency),
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">سجلّ التحصيل</h2>
        <p className="mt-1 text-sm text-ink-muted">
          ما حُصِّل في فترة: بالطريقة وبالحالة وبالمصدر. صورة الداخل، لا مستحقات المدرّسين.
        </p>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر عرض السجلّ">
          {error}
        </Alert>
      )}

      <Card>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <TextField id="from" label="من تاريخ" type="date" value={from} onChange={setFrom} />
          <TextField id="to" label="إلى تاريخ" type="date" value={to} onChange={setTo} />
          <SelectField
            id="method"
            label="الطريقة"
            value={method}
            onChange={setMethod}
            placeholder="كل الطرق"
            options={Object.entries(METHOD_LABELS).map(([value, label]) => ({ value, label }))}
          />
          <SelectField
            id="status"
            label="الحالة"
            value={status}
            onChange={setStatus}
            placeholder="كل الحالات"
            options={Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))}
          />
        </div>

        <div className="mt-4 flex flex-wrap gap-3">
          <Button onClick={load} disabled={loading}>
            اعرض
          </Button>
          {/* The same filter the screen is showing — a second set of inputs for
              the export is where the file and the page start disagreeing. */}
          <Button variant="secondary" onClick={exportFile} disabled={loading}>
            تصدير الفترة
          </Button>
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        {DIMENSIONS.map(({ field, title, labels }) => (
          <Card key={field}>
            <h3 className="mb-3 text-sm font-semibold text-ink">{title}</h3>
            {summary === null || summary[field].length === 0 ? (
              <p className="text-sm text-ink-muted">لا تحصيل في هذه الفترة.</p>
            ) : (
              <ul className="space-y-2">
                {summary[field].map((bucket) => (
                  <li
                    key={`${bucket.key ?? "-"}-${bucket.currency}`}
                    className="flex items-baseline justify-between gap-3 text-sm"
                  >
                    <span className="text-ink">
                      {bucket.key === null ? "—" : (labels[bucket.key] ?? bucket.key)}
                    </span>
                    <span className="text-ink-muted">
                      <bdi>{formatMinorMoney(bucket.amount_minor, bucket.currency)}</bdi>
                      <span className="ms-2 text-xs">({bucket.transactions})</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        ))}
      </div>

      <Table
        columns={columns}
        rows={rows}
        rowKey={(r) => r.uuid}
        caption="عمليات التحصيل في الفترة المختارة"
        state={loading ? "loading" : failed ? "error" : "ready"}
        onRetry={load}
        emptyTitle="لا عمليات في هذه الفترة"
        emptyDescription="غيّر التاريخين أو أزل المُصفِّيات."
      />
    </div>
  );
}
