"use client";

import { useCallback, useEffect, useState } from "react";

import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDate, statusLabel, statusTone } from "@/lib/labels";
import { Badge } from "@/components/ui/Badge";
import type { Order } from "@/lib/types";
import { OrdersIcon } from "@/components/icons";
import { DashboardCard, ROW_CLASS, RowIcon } from "./DashboardCard";

/** ما اشتراهُ الصفّ، بالكلمةِ التي تقولُها شاشةُ الطلباتِ نفسُها. */
const KIND_LABELS: Record<string, string> = {
  course: "شراء الكورس",
  credits: "شراء أرصدة",
  store: "شراء من المتجر",
  subscription: "اشتراك",
};

/**
 * آخرُ ثلاثةِ طلبات، برابطٍ إلى سجلِّ الطلباتِ الكامل.
 *
 * ⚠️ **بلا مبلغ، وليس لأنّ `FR-016` يمنعُه**: ما يدفعُه المشتري عن طلبِه رقمُه هو،
 * وشاشةُ `‎/orders` تعرضُه له. السببُ أضيقُ من ذلك: الصفُّ هنا يجيبُ «أينَ وصلَ
 * طلبي؟» — وهي حالةٌ وتاريخٌ — والمبلغُ يُقرَأُ بجوارِ ما اشتراه لا مقتطَعاً عن
 * سياقِه. من أرادَه فرابطُ البطاقةِ خطوةٌ واحدة.
 */
export function LatestOrdersCard() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    api
      .get<{ data: Order[] }>("/orders")
      .then((result) => setOrders(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  return (
    <DashboardCard
      title="آخر الطلبات"
      Icon={OrdersIcon}
      href="/orders"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && orders.length === 0 ? (
          <p className="text-sm text-ink-muted">لا طلبات في سجلّك بعد.</p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {orders.slice(0, 3).map((order) => (
          <li key={order.uuid} className={ROW_CLASS}>
            <RowIcon icon={<OrdersIcon className="h-4 w-4" />} />
            <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <p className="text-sm font-bold text-ink">
                {order.store_item_title ?? order.course_title ?? KIND_LABELS[order.kind] ?? order.kind}
              </p>
              <Badge tone={statusTone(order.status)}>{statusLabel(order.status)}</Badge>
            </div>
            <p className="text-xs text-ink-muted">{formatDate(order.created_at)}</p>
            </div>
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
