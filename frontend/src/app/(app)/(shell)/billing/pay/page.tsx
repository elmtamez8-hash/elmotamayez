"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { startPayment, type ChargeIntent } from "@/lib/payments";
import type { Order } from "@/lib/types";

/**
 * Paying for an order that is already waiting.
 *
 * ⚠️ THE MANUAL PATH IS ALWAYS ON THIS SCREEN, not a fallback that appears when
 * something breaks. FR-002 keeps hand-approved bank transfers working for ever,
 * and the edge case asks for more than that: if the gateway is down, the way to
 * pay must be VISIBLE. A page that throws when the provider fails stops the
 * collection that the manual route exists to keep running — so the transfer
 * instructions are rendered beside the gateway button, before anything is
 * clicked, and the gateway's failure only removes one of two options.
 *
 * ⚠️ AND THE PAGE NEVER DECIDES ANYTHING. It starts a payment and hands the payer
 * to the provider; whether they paid is the server's answer, read on the return
 * page (FR-009).
 */
export default function PayPage() {
  const params = useSearchParams();
  const orderUuid = params.get("order");

  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [intent, setIntent] = useState<ChargeIntent | null>(null);

  const load = useCallback(() => {
    if (orderUuid === null) {
      setLoading(false);

      return;
    }

    api
      .get<Order>(`/orders/${orderUuid}`)
      .then(setOrder)
      // Never a raw error: userMessage() is the only thing a user reads.
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [orderUuid]);

  useEffect(load, [load]);

  const pay = async () => {
    if (order === null) return;

    setBusy(true);
    setError("");

    try {
      const started = await startPayment(order.uuid);

      if (started.redirect_url !== null) {
        // A real page at the provider — leave ours.
        window.location.assign(started.redirect_url);

        return;
      }

      // No page: the method is a transfer, so what comes back is instructions.
      setIntent(started);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="mx-auto max-w-3xl">
        <RowsSkeleton />
      </div>
    );
  }

  if (orderUuid === null || order === null) {
    return (
      <div className="mx-auto max-w-3xl">
        <EmptyState
          title="لا يوجد طلب لسداده"
          description="ابدأ من صفحة الأرصدة واختر حزمة على الكورس الذي تدرسه، ثم عد إلى هنا للسداد."
          action={
            <Button variant="primary" href="/billing">
              الذهاب إلى رصيدي
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="mb-1 text-2xl font-bold text-ink">سداد طلبك</h1>
        <p className="text-ink-muted">
          {order.course_title ?? "طلب"} — <bdi>{formatMinorMoney(order.amount_minor, order.currency)}</bdi>
        </p>
      </header>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر بدء الدفع">
          {error}
        </Alert>
      )}

      <Card>
        <h2 className="mb-2 font-bold text-ink">الدفع الإلكتروني</h2>
        <p className="mb-4 text-sm leading-relaxed text-ink-muted">
          تنتقل إلى صفحة مزوّد الدفع لإتمام العملية، ثم تعود إلى هنا. لا تُخزَّن بيانات
          بطاقتك على المنصة في أي مرحلة.
        </p>

        <Button onClick={pay} disabled={busy}>
          {busy ? "جارٍ التحويل…" : "ادفع الآن"}
        </Button>
      </Card>

      {intent !== null && intent.instructions !== null && (
        <Alert tone="info" title="تعليمات التحويل">
          {intent.instructions}
        </Alert>
      )}

      {/* ⚠️ Rendered unconditionally, and that is the edge case's requirement:
          "the manual route appears" is stronger than "the manual route still
          works". A student who arrives while the gateway is refusing requests
          needs to see this without having to fail first. */}
      <Card>
        <h2 className="mb-2 font-bold text-ink">التحويل البنكي</h2>
        <p className="mb-4 text-sm leading-relaxed text-ink-muted">
          يمكنك التحويل إلى حساب الأكاديمية ورفع صورة الإيصال، ويؤكّده الفريق قبل تفعيل
          رصيدك. هذا الطريق متاح دائماً، ولا يتوقّف بتوقّف الدفع الإلكتروني.
        </p>

        <Link
          href="/orders"
          className="link-underline inline-flex items-center text-sm font-semibold text-primary-ink transition-all duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          رفع إيصال التحويل
        </Link>
      </Card>
    </div>
  );
}
