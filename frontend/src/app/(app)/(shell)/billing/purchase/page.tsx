"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { CardGridSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { billing, formatCredits, type CreditPackageOffer } from "@/lib/billing";
import { formatMinorMoney } from "@/lib/labels";

/**
 * Buying credits on one course.
 *
 * ⚠️ ONE TOTAL PER PACKAGE AND NO BREAKDOWN — not a design preference. The total
 * is the teacher's approved settlement rate plus two platform constants, so a
 * student shown the parts could solve for the constants and read every other
 * teacher's pay off any published total (FR-021ج).
 *
 * ⚠️ NOTHING IS CREDITED HERE (FR-018). Pressing a package creates a PENDING
 * ORDER; the credits appear when the platform approves the payment. So the
 * screen hands off to the receipt flow that already exists rather than
 * congratulating anyone.
 *
 * An empty list is a real answer, and a quiet one: the course's teacher may have
 * no approved rate yet, or the course may have stopped delivering sessions and
 * therefore stopped selling (FR-021ط). Both are the same fact from here, so both
 * get the same honest message instead of a reason the student cannot act on.
 */
export default function PurchaseCreditsPage() {
  const router = useRouter();
  const params = useSearchParams();
  const course = params.get("course");

  const [offers, setOffers] = useState<CreditPackageOffer[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [buying, setBuying] = useState<string | null>(null);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    if (!course) {
      setLoading(false);

      return;
    }

    setLoading(true);
    setFailed(false);

    billing
      .packages(course)
      .then((rows) => setOffers(rows ?? []))
      .catch((err: unknown) => {
        setFailed(true);
        setError(errorMessage(err, "تعذّر تحميل الحزم المتاحة. أعد المحاولة."));
      })
      .finally(() => setLoading(false));
  }, [course]);

  useEffect(load, [load]);

  function buy(offer: CreditPackageOffer) {
    if (!course) return;

    setBuying(offer.uuid);
    setError("");

    billing
      .purchase(course, offer.uuid)
      .then(() => {
        /*
         * To the orders list, which is where the receipt is uploaded — there is
         * no per-order page, and inventing `/orders/{uuid}` here would have sent
         * every buyer to a 404 on the one screen that follows a payment.
         *
         * A success screen would be worse than a wrong route: it would tell a
         * student the credits are theirs when no payment has been approved yet
         * (FR-018). The next step is uploading the transfer receipt, so the next
         * screen is the one that takes it.
         */
        router.push("/orders");
      })
      .catch((err: unknown) => {
        // 422 carries the reason the Action refused — a retired package, a
        // course that stopped selling, the unredeemed ceiling. Each is
        // actionable and each is already written in Arabic on the server.
        setError(errorMessage(err, "تعذّر بدء الشراء. أعد المحاولة."));
        setBuying(null);
      });
  }

  if (!course) {
    return (
      <EmptyState
        title="اختر الكورس أولاً"
        description="تُشترى الأرصدة على كورس بعينه، لأن سعرها يختلف باختلاف المدرّس."
        action={
          <Button variant="secondary" href="/billing">
            رصيدي
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">شراء أرصدة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          كل رصيد يعادل حصة واحدة، ولا يُخصم إلا عن حصة قُدِّمت فعلاً.
        </p>
      </div>

      {error && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {error}
        </Alert>
      )}

      {loading && <CardGridSkeleton />}

      {!loading && failed && (
        <EmptyState
          title="تعذّر تحميل الحزم"
          description="أعد المحاولة بعد قليل."
          action={
            <Button variant="secondary" onClick={load}>
              إعادة المحاولة
            </Button>
          }
        />
      )}

      {!loading && !failed && offers.length === 0 && (
        <EmptyState
          title="لا توجد حزم متاحة على هذا الكورس حالياً"
          description="تواصل مع مدرّسك لمعرفة موعد إتاحتها."
          action={
            <Button variant="secondary" href="/billing">
              رصيدي
            </Button>
          }
        />
      )}

      {!loading && !failed && offers.length > 0 && (
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {offers.map((offer) => (
            <li key={offer.uuid}>
              <Card>
                <h2 className="text-base font-semibold text-ink">{offer.name}</h2>

                <p className="mt-2 text-2xl font-extrabold text-ink">
                  <bdi>{formatCredits(offer.credits)}</bdi>{" "}
                  <span className="text-sm font-medium text-ink-muted">حصة</span>
                </p>

                {/* One number. There is no breakdown to expand, and no toggle
                    that would suggest there is. */}
                <p className="mt-1 text-lg font-bold text-ink">
                  {formatMinorMoney(offer.total_minor, offer.currency)}
                </p>

                <p className="mt-2 text-xs text-ink-muted">
                  {offer.validity_days === null
                    ? "لا تنتهي صلاحية الأرصدة."
                    : `صالحة ${offer.validity_days.toLocaleString("ar-EG")} يوماً من الاعتماد.`}
                </p>

                <div className="mt-4">
                  <Button
                    variant="primary"
                    onClick={() => buy(offer)}
                    disabled={buying !== null}
                    loading={buying === offer.uuid}
                    loadingLabel="جارٍ التحضير…"
                  >
                    اختيار هذه الحزمة
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-ink-muted">
        لا يُضاف أي رصيد قبل اعتماد الدفع. بعد اختيار الحزمة ترفع إيصال التحويل،
        ويظهر الرصيد فور اعتماده.
      </p>
    </div>
  );
}
