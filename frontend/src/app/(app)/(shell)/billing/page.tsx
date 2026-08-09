"use client";

import { useCallback, useEffect, useState } from "react";
import { BalanceSummary } from "@/components/billing/BalanceSummary";
import { TransactionList } from "@/components/billing/TransactionList";
import { Alert } from "@/components/ui/Alert";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { CardGridSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { billing, type CreditBalance, type CreditTransaction } from "@/lib/billing";

/**
 * The student's credits: what they hold in each course, and every movement.
 *
 * Balances and the ledger are two requests, not one: the ledger is paginated and
 * the balances are not, and folding them together would either re-fetch every
 * balance on each page turn or make the first page carry the whole history.
 *
 * Errors go through errorMessage(), never raw. A failed billing request is
 * exactly where a stack trace or a bare 500 would be most alarming.
 */
export default function BillingPage() {
  const [balances, setBalances] = useState<CreditBalance[]>([]);
  const [entries, setEntries] = useState<CreditTransaction[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loadingBalances, setLoadingBalances] = useState(true);
  const [loadingEntries, setLoadingEntries] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");

  const loadBalances = useCallback(() => {
    setLoadingBalances(true);
    setFailed(false);

    billing
      .balances()
      .then((res) => setBalances(res.data ?? []))
      .catch((err: unknown) => {
        setFailed(true);
        setError(errorMessage(err, "تعذّر تحميل رصيدك. أعد المحاولة."));
      })
      .finally(() => setLoadingBalances(false));
  }, []);

  const loadEntries = useCallback((target: number) => {
    setLoadingEntries(true);

    billing
      .transactions(undefined, target)
      .then((res) => {
        setEntries(res.data ?? []);
        setLastPage(res.meta?.last_page ?? 1);
      })
      .catch((err: unknown) =>
        setError(errorMessage(err, "تعذّر تحميل سجلّ الحركة. أعد المحاولة.")),
      )
      .finally(() => setLoadingEntries(false));
  }, []);

  useEffect(loadBalances, [loadBalances]);
  useEffect(() => loadEntries(page), [loadEntries, page]);

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-2xl font-bold text-ink">رصيدي</h1>
        <p className="mt-1 text-sm text-ink-muted">
          حصصك المتبقّية عند كل معلّم، وسجلّ كل حركة عليها.
        </p>
      </header>

      {error !== "" && (
        <Alert tone="danger" title="تعذّرت العملية">
          {error}
        </Alert>
      )}

      <section aria-labelledby="balances-heading" className="space-y-4">
        <h2 id="balances-heading" className="text-lg font-semibold text-ink">
          الأرصدة
        </h2>

        {loadingBalances ? (
          <CardGridSkeleton count={2} variant="course" />
        ) : balances.length === 0 ? (
          <EmptyState
            title={failed ? "تعذّر تحميل الأرصدة" : "لا رصيد لك بعد"}
            description={
              failed
                ? "حدث خطأ أثناء جلب رصيدك. تحقّق من اتصالك ثم أعد المحاولة."
                : "بعد أول عملية شراء ستظهر هنا حصصك المتبقّية عند كل معلّم."
            }
          />
        ) : (
          <BalanceSummary balances={balances} />
        )}
      </section>

      <section aria-labelledby="ledger-heading" className="space-y-4">
        <h2 id="ledger-heading" className="text-lg font-semibold text-ink">
          سجلّ الحركة
        </h2>

        <TransactionList
          transactions={entries}
          state={loadingEntries ? "loading" : "ready"}
          page={page}
          lastPage={lastPage}
          onPageChange={setPage}
          onRetry={() => loadEntries(page)}
        />
      </section>
    </div>
  );
}
