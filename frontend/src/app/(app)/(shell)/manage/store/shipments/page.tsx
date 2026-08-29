"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Table, type Column } from "@/components/ui/Table";
import { userMessage } from "@/lib/errors";
import { store, type Shipment, type ShipmentStatus } from "@/lib/store";
import { Alert } from "@/components/ui/Alert";

/**
 * The fulfilment queue (spec 011 · US1 · FR-008).
 *
 * ⚠️ THE NEXT STATES COME FROM THE SERVER (`next_statuses`), NEVER FROM A LADDER
 * WRITTEN HERE. `returned` follows a delivery attempt and nothing else, so a
 * client that derived an order would offer it after «وصل» — two spellings of one
 * rule, one on the screen and one at the door.
 *
 * ⚠️ AND EVERY MOVE NOTIFIES THE BUYER, so the button is disabled while the
 * request is in flight: a second press is a second message about the same step.
 */
export default function ShipmentQueuePage() {
  const [rows, setRows] = useState<Shipment[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    store
      .shipments()
      .then((res) => {
        setRows(res.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  async function advance(shipment: Shipment, status: ShipmentStatus) {
    setBusy(shipment.uuid);
    setProblem(null);

    try {
      const res = await store.advanceShipment(shipment.uuid, { status });
      setRows((current) => current.map((row) => (row.uuid === res.data.uuid ? res.data : row)));
    } catch (error) {
      // Never a raw error: the refusal names both ends of the move it refused.
      setProblem(userMessage(error));
    } finally {
      setBusy(null);
    }
  }

  const columns: Column<Shipment>[] = [
    { key: "recipient", header: "المستلم", render: (row) => row.recipient_name ?? "—" },
    { key: "phone", header: "الهاتف", render: (row) => row.phone ?? "—" },
    { key: "address", header: "العنوان", render: (row) => row.address_line ?? "—" },
    {
      key: "status",
      header: "الحالة",
      render: (row) => <Badge tone="info">{row.status_label}</Badge>,
    },
    {
      key: "advance",
      header: "الخطوة التالية",
      render: (row) =>
        row.next_statuses.length === 0 ? (
          <span className="text-xs text-ink-muted">انتهت</span>
        ) : (
          <div className="flex flex-wrap gap-2">
            {row.next_statuses.map((next) => (
              <Button
                key={next.value}
                size="sm"
                variant="ghost"
                disabled={busy === row.uuid}
                onClick={() => advance(row, next.value)}
              >
                {next.label}
              </Button>
            ))}
          </div>
        ),
    },
  ];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">الشحنات</h1>
        <p className="mt-1 text-sm text-ink-muted">
          النسخ المطبوعة التي دُفِع ثمنها، ومكان كلٍّ منها الآن. كل تغيير تصل به
          رسالة إلى المشتري.
        </p>
      </header>

      {problem && <Alert tone="danger" title={problem} />}

      <Table
        caption="طابور الشحنات"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.uuid}
        state={state}
        emptyTitle="لا شحنات"
        emptyDescription="تظهر هنا كل نسخة مطبوعة اعتُمِد دفعها وتنتظر التجهيز."
        onRetry={load}
      />
    </div>
  );
}
