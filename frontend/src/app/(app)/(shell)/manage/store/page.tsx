"use client";

import { useCallback, useEffect, useState } from "react";
import { StoreItemForm } from "@/components/store/StoreItemForm";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Table, type Column } from "@/components/ui/Table";
import { formatMinorMoney } from "@/lib/labels";
import { store, teacherNetMinor, type StoreItem } from "@/lib/store";

/**
 * The teacher's shelf (spec 011 · US1 · FR-001 · FR-002).
 *
 * ⚠️ `stock === null` IS «غير محدود», NEVER A ZERO. A digital item has no stock
 * column at all, and a table that rendered `stock ?? 0` would tell the teacher
 * every file they sell is sold out.
 */
export default function ManageStorePage() {
  const [items, setItems] = useState<StoreItem[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [editing, setEditing] = useState<StoreItem | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    setState("loading");

    store
      .items()
      .then((res) => {
        setItems(res.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  // The rate comes from any row the API sent. Before the first product exists
  // there is nothing to read, and 10% is the shipped default — a number the
  // form re-reads from the server the moment one is saved.
  const commissionBps = items[0]?.commission_bps ?? 1000;

  const columns: Column<StoreItem>[] = [
    { key: "title", header: "المنتَج", render: (row) => row.title },
    { key: "kind", header: "النوع", render: (row) => row.kind_label },
    {
      key: "price",
      header: "سعر البيع",
      numeric: true,
      render: (row) => formatMinorMoney(row.price_minor, row.currency),
    },
    {
      key: "net",
      header: "نصيبك",
      numeric: true,
      render: (row) =>
        formatMinorMoney(teacherNetMinor(row.price_minor, row.commission_bps), row.currency),
    },
    {
      key: "stock",
      header: "المخزون",
      numeric: true,
      render: (row) => (row.stock === null ? "غير محدود" : String(row.stock)),
    },
    {
      key: "state",
      header: "الحالة",
      // The label carries the meaning and the tone is emphasis only, so a
      // reader who cannot tell the colours apart still reads the row.
      render: (row) =>
        row.is_active ? <Badge tone="success">معروض</Badge> : <Badge tone="neutral">موقوف</Badge>,
    },
    {
      key: "edit",
      header: "تحرير",
      render: (row) => (
        <Button size="sm" variant="ghost" onClick={() => setEditing(row)}>
          تعديل
        </Button>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-ink">المتجر</h1>
          <p className="mt-1 text-sm text-ink-muted">
            كتبك ومذكّراتك — نسخة رقمية تُسلَّم فور اعتماد الدفع، أو نسخة مطبوعة
            تُشحَن إلى الباب.
          </p>
        </div>

        {!creating && !editing && (
          <Button onClick={() => setCreating(true)}>منتَج جديد</Button>
        )}
      </header>

      {(creating || editing) && (
        <Card>
          <StoreItemForm
            item={editing ?? undefined}
            commissionBps={commissionBps}
            onSaved={() => {
              setCreating(false);
              setEditing(null);
              load();
            }}
            onCancel={() => {
              setCreating(false);
              setEditing(null);
            }}
          />
        </Card>
      )}

      <Table
        caption="منتجات المتجر"
        columns={columns}
        rows={items}
        rowKey={(row) => row.uuid}
        state={state}
        emptyTitle="لا منتجات بعد"
        emptyDescription="أضِف كتاباً أو مذكّرة ليظهر هنا، ويراه طلابك في متجرك."
        onRetry={load}
      />

      <p className="text-xs text-ink-muted">
        السعر الذي تكتبه هو ما يدفعه الطالب، وعمولة المنصّة تُقتطع منه ولا تُضاف
        فوقه. المنتَج لا يُحذف — أوقِف عرضه، ليبقى إيصال كل من اشتراه يشير إليه.
      </p>
    </div>
  );
}
