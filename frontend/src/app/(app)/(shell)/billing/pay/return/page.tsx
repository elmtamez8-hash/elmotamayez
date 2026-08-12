"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { fetchPayment, isSettled, type PaymentTransaction } from "@/lib/payments";

/**
 * Where the provider sends the payer back to.
 *
 * ⚠️ THIS PAGE DISPLAYS; IT NEVER DECIDES (FR-009). A provider's return URL is a
 * redirect in the payer's own browser — anyone can type it, with any query string
 * they like. What settled the payment is the signed callback the server verified,
 * so this screen asks the API what happened and shows that answer. A page that
 * read `?status=success` and congratulated the payer would be granting credits on
 * the strength of a URL.
 *
 * THREE STATES, all of them real:
 *   · still pending — the callback has not arrived yet, which is normal for a few
 *     seconds and is NOT a failure;
 *   · settled and captured;
 *   · settled and not captured, with the reason the server recorded.
 *
 * The pending state polls, because the payer is standing here waiting and the
 * answer arrives from somewhere else entirely.
 */
export default function PaymentReturnPage() {
  const params = useSearchParams();
  const transactionUuid = params.get("transaction");

  const [payment, setPayment] = useState<PaymentTransaction | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    if (transactionUuid === null) {
      setLoading(false);

      return;
    }

    fetchPayment(transactionUuid)
      .then(setPayment)
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [transactionUuid]);

  useEffect(load, [load]);

  // Six seconds is a compromise with a reason: the callback usually lands in
  // under a second, and a payer who sees "قيد المعالجة" for a minute needs the
  // page to keep asking rather than to have given up at the first miss.
  useEffect(() => {
    if (payment === null || isSettled(payment.status)) return;

    const timer = setInterval(load, 6000);

    return () => clearInterval(timer);
  }, [payment, load]);

  if (loading) {
    return (
      <div className="mx-auto max-w-2xl">
        <RowsSkeleton />
      </div>
    );
  }

  if (transactionUuid === null || payment === null) {
    return (
      <div className="mx-auto max-w-2xl">
        <EmptyState
          title="لا نعرف أي عملية تسأل عنها"
          description={error !== "" ? error : "تحقّق من حالة طلباتك من صفحة الطلبات."}
          action={
            <Button variant="primary" href="/orders">
              طلباتي
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold text-ink">نتيجة الدفع</h1>

      {!isSettled(payment.status) && (
        <Alert tone="info" title="عمليتك قيد المعالجة">
          نتابع تأكيد الدفع مع المزوّد. تُحدَّث هذه الصفحة تلقائياً، ويمكنك إغلاقها —
          لا يتوقّف التأكيد على بقائك هنا.
        </Alert>
      )}

      {payment.status === "captured" && (
        <Alert tone="success" title="تمّ الدفع">
          استلمنا دفعتك وأُضيف رصيدك. يمكنك الحجز الآن.
        </Alert>
      )}

      {isSettled(payment.status) && payment.status !== "captured" && (
        <Alert tone="danger" title="لم تكتمل العملية">
          {payment.failure_reason ?? "لم يؤكّد مزوّد الدفع العملية. لم يُخصم منك شيء."}
        </Alert>
      )}

      <Card>
        <dl className="space-y-3 text-sm">
          <div className="flex items-baseline justify-between gap-4">
            <dt className="text-ink-muted">الحالة</dt>
            <dd className="font-semibold text-ink">{payment.status_label}</dd>
          </div>

          {payment.method_label !== null && (
            <div className="flex items-baseline justify-between gap-4">
              <dt className="text-ink-muted">وسيلة الدفع</dt>
              <dd className="font-semibold text-ink">{payment.method_label}</dd>
            </div>
          )}
        </dl>
      </Card>

      <div className="flex flex-wrap gap-3">
        <Link
          href="/billing"
          className="link-underline text-sm font-semibold text-primary-ink transition-all duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          رصيدي
        </Link>
        <Link
          href="/orders"
          className="link-underline text-sm font-semibold text-primary-ink transition-all duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        >
          طلباتي
        </Link>
      </div>
    </div>
  );
}
