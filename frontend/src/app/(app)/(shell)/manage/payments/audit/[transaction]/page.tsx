"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Table, type Column } from "@/components/ui/Table";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { OrdersIcon } from "@/components/icons";
import { userMessage } from "@/lib/errors";
import { counted, formatDateTime, NOUNS } from "@/lib/labels";
import {
  eventLabel,
  paymentAudit,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  subjectLabel,
  type PaymentAuditChain,
  type PaymentAuditEntry,
} from "@/lib/payment-audit";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

type Consumption = PaymentAuditChain["consumptions"][number];

/**
 * One payment, followed to the end: money → order → credits bought → what was
 * left in the batch → every session it paid for, and the decisions taken on the
 * way (FR-028).
 *
 * ⛔ `GET /admin/payments/audit/{transaction}` answered this since 010 and no
 * screen asked it: the list showed WHAT was decided, and «what became of the
 * money» — the question an auditor actually opens the log for — had no page.
 *
 * ⚠️ A NULL IS «NOT PART OF THIS SALE», NEVER ZERO. A course order buys no
 * credits at all, so `credits_purchased` is null there and prints «—»; a zero
 * would read as a sale that minted nothing.
 */
export default function PaymentAuditChainPage() {
  const params = useParams<{ transaction: string }>();
  const transaction = params.transaction;
  const timeZone = useViewerTimeZone();

  const [chain, setChain] = useState<PaymentAuditChain | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setState("loading");
    setError("");

    paymentAudit
      .chain(transaction)
      .then((res) => {
        setChain(res.data);
        setState("ready");
      })
      .catch((err: unknown) => {
        setError(userMessage(err));
        setState("error");
      });
  }, [transaction]);

  useEffect(load, [load]);

  const header = (
    <PageHeader
      Icon={OrdersIcon}
      title="تتبّع دفعة"
      description="ما الذي اشتراه هذا المال، وكم بقي منه، وعلى أي حصص صُرف، ومن قرّر ماذا عليه."
      actions={
        <Button href="/manage/payments/audit" variant="secondary" size="sm">
          العودة إلى السجلّ
        </Button>
      }
    />
  );

  if (state === "loading") {
    return (
      <div className="space-y-6">
        {header}
        <RowsSkeleton />
      </div>
    );
  }

  if (state === "error" || chain === null) {
    return (
      <div className="space-y-6">
        {header}
        <ErrorState title="تعذّر تحميل الدفعة" description={error || undefined} onRetry={load} />
      </div>
    );
  }

  const { payment } = chain;
  const credits = (value: number | null) => (value === null ? "—" : counted(value, NOUNS.sessions));

  const facts: { label: string; value: string; mono?: boolean }[] = [
    { label: "الحالة", value: PAYMENT_STATUS_LABELS[payment.status] ?? payment.status },
    {
      label: "الطريقة",
      value: payment.method === null ? "—" : (PAYMENT_METHOD_LABELS[payment.method] ?? payment.method),
    },
    { label: "المزوّد", value: payment.provider, mono: true },
    { label: "تاريخ التحصيل", value: formatDateTime(payment.settled_at, timeZone) },
    { label: "الطلب", value: chain.order_uuid ?? "—", mono: true },
    { label: "الرصيد المشترى", value: credits(chain.credits_purchased) },
    { label: "المتبقّي في الدفعة", value: credits(chain.credits_remaining_in_lot) },
  ];

  const consumptionColumns: Column<Consumption>[] = [
    { key: "occurred_at", header: "الوقت", render: (c) => formatDateTime(c.occurred_at, timeZone) },
    { key: "credits", header: "الرصيد", render: (c) => counted(c.credits, NOUNS.sessions) },
    {
      key: "entry",
      header: "القيد",
      render: (c) => (
        <span className="font-mono text-xs" dir="ltr">
          {c.entry_uuid ?? "—"}
        </span>
      ),
    },
  ];

  const trailColumns: Column<PaymentAuditEntry>[] = [
    { key: "occurred_at", header: "الوقت", render: (e) => formatDateTime(e.occurred_at, timeZone) },
    { key: "event", header: "القرار", render: (e) => eventLabel(e.event) },
    {
      key: "subject",
      header: "على",
      render: (e) => <span className="text-xs text-ink-muted">{subjectLabel(e.subject_type)}</span>,
    },
    { key: "actor", header: "المنفّذ", render: (e) => e.actor_name ?? "النظام" },
    {
      key: "ip",
      header: "من",
      render: (e) => (
        <span className="font-mono text-xs" dir="ltr">
          {e.ip_address ?? "—"}
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      {header}

      <Card>
        <p className="mb-4 text-xs text-ink-muted">
          رقم الدفعة{" "}
          <bdi className="font-mono" dir="ltr">
            {payment.uuid}
          </bdi>
        </p>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {facts.map((fact) => (
            <div key={fact.label}>
              <dt className="text-xs text-ink-muted">{fact.label}</dt>
              <dd className={`mt-1 text-sm text-ink ${fact.mono ? "font-mono break-all" : ""}`}>
                {fact.mono ? <bdi dir="ltr">{fact.value}</bdi> : fact.value}
              </dd>
            </div>
          ))}
        </dl>
      </Card>

      <section className="space-y-3" aria-labelledby="chain-consumptions">
        <SectionHeading id="chain-consumptions" title="على ماذا صُرف" />
        <Table
          columns={consumptionColumns}
          rows={chain.consumptions}
          // Two draws of the same size in the same second are two sessions.
          rowKey={(c, index) => `${c.entry_uuid ?? "none"}-${index}`}
          caption="الحصص التي صُرف عليها رصيد هذه الدفعة"
          emptyTitle="لم يُصرف شيء من هذه الدفعة بعد"
          emptyDescription="تظهر هنا كل حصة يُخصم رصيدها من هذه الدفعة."
        />
      </section>

      <section className="space-y-3" aria-labelledby="chain-trail">
        <SectionHeading id="chain-trail" title="القرارات على هذه الدفعة وطلبها" />
        <Table
          columns={trailColumns}
          rows={chain.trail}
          rowKey={(e) => JSON.stringify(e)}
          caption="القرارات المسجّلة على الدفعة وطلبها بترتيب زمني"
          emptyTitle="لا قرارات مسجّلة على هذه الدفعة"
          emptyDescription="يظهر هنا كل اعتماد ورفض وعكس يخص هذه الدفعة أو طلبها."
        />
      </section>
    </div>
  );
}
