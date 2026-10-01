"use client";

import Link from "next/link";
import { useState } from "react";
import { PurchaseDialog } from "@/components/store/PurchaseDialog";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { useAuth } from "@/lib/auth-context";
import type { PublicStoreItem } from "@/lib/public-api";
import type { StoreItem } from "@/lib/store";

/**
 * The product page's buy box: a guest is sent to sign in and brought back here;
 * a signed-in reader buys through the same `PurchaseDialog` the in-app list uses.
 *
 * The dialog takes the buyer's `StoreItem` shape; the public payload carries
 * every field it reads except raw stock, which it uses only to cap the quantity —
 * `null` («cannot run out») leaves the server's own stock claim as the guard.
 */
export function StoreBuyPanel({ item }: { item: PublicStoreItem }) {
  const { user, loading } = useAuth();
  const [buying, setBuying] = useState(false);
  const [bought, setBought] = useState(false);

  if (!item.is_available) {
    return <Alert tone="warning" title="نفدت النسخ المتاحة من هذا المنتج حالياً." />;
  }

  if (bought) {
    return (
      <Alert tone="success" title="سُجِّل طلبك. الخطوة التالية: ادفع وارفع صورة الإيصال.">
        <div className="mt-2 flex flex-wrap gap-4">
          <Link href="/orders" className="font-medium text-primary-ink underline underline-offset-4">
            ادفع الآن من صفحة الطلبات
          </Link>
          <Link href="/purchases" className="font-medium text-primary-ink underline underline-offset-4">
            مشترياتي
          </Link>
        </div>
      </Alert>
    );
  }

  if (loading) {
    return <div className="h-11 animate-pulse rounded-xl bg-primary-soft" aria-hidden />;
  }

  if (user === null) {
    return (
      <Link
        href={`/login?next=${encodeURIComponent(`/store/${item.uuid}`)}`}
        className="block w-full rounded-xl bg-primary px-5 py-3 text-center text-sm font-semibold text-white transition hover:brightness-110"
      >
        سجّل الدخول لتشتري
      </Link>
    );
  }

  if (buying) {
    const asBuyerItem: StoreItem = {
      uuid: item.uuid,
      kind: item.kind,
      kind_label: item.kind_label,
      title: item.title,
      excerpt: item.excerpt,
      description: null,
      price_minor: item.price_minor,
      currency: item.currency,
      shipping_fee_minor: item.shipping_fee_minor,
      stock: null,
      is_active: true,
      cover_url: item.cover_url,
      created_at: null,
    };

    return (
      <PurchaseDialog
        item={asBuyerItem}
        onDone={() => {
          setBuying(false);
          setBought(true);
        }}
        onCancel={() => setBuying(false)}
      />
    );
  }

  return (
    <Button fullWidth onClick={() => setBuying(true)}>
      اشترِ الآن
    </Button>
  );
}
