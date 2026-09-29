"use client";

import { useCallback, useEffect, useState, type ComponentType } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { FilterBar } from "@/components/ui/FilterBar";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatStrip } from "@/components/ui/StatStrip";
import { StatTile } from "@/components/ui/StatTile";
import { EmptyState } from "@/components/ui/states/EmptyState";
import {
  CheckIcon,
  ClockIcon,
  ReturnIcon,
  ShipmentIcon,
  TruckIcon,
  type IconProps,
} from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { counted, type StatusTone } from "@/lib/labels";
import { matchesSearch } from "@/lib/search-text";
import { store, type Shipment, type ShipmentStatus } from "@/lib/store";

/** «٣ شحنات» — built with `counted()`, never a template literal. */
const SHIPMENTS = { one: "شحنة واحدة", two: "شحنتان", few: "شحنات", many: "شحنةً", other: "شحنة" };

/** The ladder's order, for the chips only — the NEXT step still comes from the server. */
const ORDER: ShipmentStatus[] = ["pending", "packed", "shipped", "delivered", "returned"];

/**
 * The badge's tone per state. The WORD is the server's `status_label`; the tone
 * is emphasis: waiting on the teacher is the one that asks for attention.
 */
const TONE: Record<ShipmentStatus, StatusTone> = {
  pending: "warning",
  packed: "info",
  shipped: "info",
  delivered: "success",
  returned: "neutral",
};

/** The icon on a next-step button, so the step is recognised before it is read. */
const STEP_ICON: Record<ShipmentStatus, ComponentType<IconProps>> = {
  pending: ClockIcon,
  packed: ShipmentIcon,
  shipped: TruckIcon,
  delivered: CheckIcon,
  returned: ReturnIcon,
};

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
 *
 * ⚠️ THE STRIP'S PER-STATE FIGURES ARE COUNTED ONLY WHEN THE WHOLE QUEUE IS ON
 * SCREEN. The endpoint pages at twenty and sends one aggregate, `meta.total`;
 * counting states over a first page would print «وصل ٣» for a teacher who has
 * delivered forty (`manage-pages.md` §2 — only figures the server knows). Past
 * one page the strip keeps the server's total alone.
 */
export default function ShipmentQueuePage() {
  const [rows, setRows] = useState<Shipment[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("all");

  const load = useCallback(() => {
    setState("loading");

    store
      .shipments()
      .then((res) => {
        setRows(res.data ?? []);
        setTotal(res.meta?.total ?? null);
        setLabels(res.meta?.statuses ?? {});
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  async function advance(shipment: Shipment, next: ShipmentStatus) {
    setBusy(shipment.uuid);
    setProblem(null);

    try {
      const moved = await store.advanceShipment(shipment.uuid, { status: next });
      setRows((current) => current.map((row) => (row.uuid === moved.uuid ? moved : row)));
    } catch (error) {
      // Never a raw error: the refusal names both ends of the move it refused.
      setProblem(userMessage(error));
    } finally {
      setBusy(null);
    }
  }

  const countOf = (key: ShipmentStatus) => rows.filter((row) => row.status === key).length;
  const labelOf = (key: ShipmentStatus) =>
    labels[key] ?? rows.find((row) => row.status === key)?.status_label ?? key;
  const whole = total === null || rows.length >= total;

  // A chip per state the server names, or — without its list — per state on screen.
  const chipKeys = ORDER.filter((key) => labels[key] !== undefined || countOf(key) > 0);

  const shown = rows.filter(
    (row) =>
      (status === "all" || row.status === status) &&
      matchesSearch(query, row.recipient_name, row.phone, row.address_line, row.tracking_ref),
  );

  const clear = () => {
    setQuery("");
    setStatus("all");
  };

  const columns: Column<Shipment>[] = [
    {
      key: "recipient",
      header: "المستلم",
      render: (row) => <span className="font-medium text-ink">{row.recipient_name ?? "—"}</span>,
    },
    { key: "phone", header: "الهاتف", render: (row) => <bdi>{row.phone ?? "—"}</bdi> },
    { key: "address", header: "العنوان", render: (row) => row.address_line ?? "—" },
    {
      key: "status",
      header: "الحالة",
      render: (row) => <Badge tone={TONE[row.status] ?? "info"}>{row.status_label}</Badge>,
    },
    {
      key: "advance",
      header: "الخطوة التالية",
      render: (row) =>
        row.next_statuses.length === 0 ? (
          <span className="text-xs text-ink-muted">انتهت</span>
        ) : (
          <div className="flex flex-wrap gap-2">
            {row.next_statuses.map((next) => {
              const StepIcon = STEP_ICON[next.value] ?? ShipmentIcon;

              return (
                <Button
                  key={next.value}
                  size="sm"
                  variant="ghost"
                  iconStart={<StepIcon />}
                  disabled={busy === row.uuid}
                  onClick={() => advance(row, next.value)}
                >
                  {next.label}
                </Button>
              );
            })}
          </div>
        ),
    },
  ];

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={ShipmentIcon}
        title="الشحنات"
        description={
          <>
            النسخ المطبوعة التي دُفِع ثمنها، ومكان كلٍّ منها الآن. كل تغيير تصل به
            رسالة إلى المشتري.
          </>
        }
      />

      {state === "ready" && rows.length > 0 && (
        <StatStrip label="ملخّص الشحنات" columns={whole ? 4 : 2}>
          <StatTile label="كلّ الشحنات" value={total ?? rows.length} Icon={ShipmentIcon} />
          {whole && <StatTile label={labelOf("pending")} value={countOf("pending")} Icon={ClockIcon} />}
          {whole && <StatTile label={labelOf("shipped")} value={countOf("shipped")} Icon={TruckIcon} />}
          {whole && (
            <StatTile label={labelOf("delivered")} value={countOf("delivered")} Icon={CheckIcon} emphasis />
          )}
        </StatStrip>
      )}

      <section aria-labelledby="shipment-queue" className="space-y-4">
        <SectionHeading id="shipment-queue" Icon={ShipmentIcon} title="طابور الشحنات" />

        {problem && <Alert tone="danger" title={problem} />}

        {state === "ready" && rows.length > 0 && (
          <FilterBar
            search={{
              id: "shipment-search",
              label: "ابحث في الشحنات",
              value: query,
              onChange: setQuery,
              placeholder: "اسم المستلم أو هاتفه أو عنوانه",
            }}
            filters={{
              label: "حالة الشحنة",
              value: status,
              onChange: setStatus,
              options: [
                { key: "all", label: "الكل", count: rows.length },
                ...chipKeys.map((key) => ({ key, label: labelOf(key), count: countOf(key) })),
              ],
            }}
            summary={counted(shown.length, SHIPMENTS)}
          />
        )}

        {state === "ready" && rows.length > 0 && shown.length === 0 ? (
          <EmptyState
            title="لا شحنة تطابق البحث"
            description="لا شحنة في الطابور تطابق ما اخترته. جرّب كلمة أخرى أو حالة أخرى."
            action={
              <Button variant="secondary" size="sm" onClick={clear}>
                مسح البحث
              </Button>
            }
          />
        ) : (
          <Table
            caption="طابور الشحنات"
            columns={columns}
            rows={shown}
            rowKey={(row) => row.uuid}
            state={state}
            emptyIcon={ShipmentIcon}
            emptyTitle="لا شحنات"
            emptyDescription="تظهر هنا كل نسخة مطبوعة اعتُمِد دفعها وتنتظر التجهيز."
            onRetry={load}
          />
        )}

        {state === "ready" && !whole && (
          <p className="text-sm text-ink-muted">
            يظهر هنا أوّل {arabicNumber(rows.length)} من {counted(total ?? rows.length, SHIPMENTS)}.
          </p>
        )}
      </section>
    </div>
  );
}
