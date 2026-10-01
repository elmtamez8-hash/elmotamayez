"use client";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { formatMinorMoney } from "@/lib/labels";
import type { StoreItem } from "@/lib/store";

/**
 * One product, as a buyer sees it.
 *
 * ⚠️ `stock === null` MEANS «CANNOT RUN OUT», AND THE CARD MUST SAY NOTHING AT
 * ALL IN THAT CASE. Rendering `stock ?? 0` — or «٠ متبقّية» — tells every buyer
 * that a file is sold out, which is the exact reading `ClaimStock` branches on
 * `kind` to avoid on the server.
 *
 * ⚠️ AND NO COMMISSION AND NO TEACHER SHARE APPEAR HERE. The buyer sees the
 * price they pay; the split is the teacher's business and the platform's, and a
 * number on both screens is one subtraction away from the other side's rate.
 */
export function StoreItemCard({
  item,
  onBuy,
}: {
  item: StoreItem;
  onBuy: (item: StoreItem) => void;
}) {
  const soldOut = item.stock !== null && item.stock <= 0;

  return (
    <Card interactive>
      <div className="space-y-3">
        {item.cover_url && (
          // eslint-disable-next-line @next/next/no-img-element -- a stored upload, not a build asset
          <img
            src={item.cover_url}
            alt=""
            className="mx-auto aspect-[3/4] w-32 rounded-lg border border-line object-cover shadow-sm"
          />
        )}

        <div className="flex items-start justify-between gap-3">
          <h4 className="text-base font-semibold text-ink">{item.title}</h4>
          <Badge tone="neutral">{item.kind_label}</Badge>
        </div>

        {/* The excerpt, or the start of the description when none was written —
            the form promises «اتركه فارغاً ليظهر أول الوصف». */}
        {(item.excerpt || item.description) && (
          <p className="line-clamp-3 text-sm text-ink-muted">{item.excerpt || item.description}</p>
        )}

        <div className="flex items-baseline gap-2">
          <span className="text-lg font-bold text-ink">
            {formatMinorMoney(item.price_minor, item.currency)}
          </span>

          {item.shipping_fee_minor !== null && item.shipping_fee_minor > 0 && (
            <span className="text-xs text-ink-muted">
              {`+ شحن ${formatMinorMoney(item.shipping_fee_minor, item.currency)}`}
            </span>
          )}
        </div>

        {/* Only a stocked item says anything about stock, and only when it is low
            enough to matter or gone. A file says nothing. */}
        {soldOut ? (
          <Badge tone="danger">نفدت النسخ</Badge>
        ) : item.stock !== null && item.stock <= 5 ? (
          <Badge tone="warning">{`بقي ${item.stock}`}</Badge>
        ) : null}

        <Button fullWidth disabled={soldOut} onClick={() => onBuy(item)}>
          {soldOut ? "غير متاح" : "اشترِ"}
        </Button>
      </div>
    </Card>
  );
}
