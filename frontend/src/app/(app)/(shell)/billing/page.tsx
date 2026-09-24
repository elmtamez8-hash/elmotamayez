"use client";

import { useCallback, useEffect, useState } from "react";
import { BalanceSummary } from "@/components/billing/BalanceSummary";
import { TermsConsentCard } from "@/components/billing/TermsConsentCard";
import { TransactionList } from "@/components/billing/TransactionList";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { CreditsIcon, ListIcon, WalletIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { CardGridSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { dashboardAudience } from "@/lib/dashboard-audience";
import { navLabel } from "@/lib/panel-nav";
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
  const { user } = useAuth();

  /*
   * ⚠️ A GUARDIAN REACHES THIS SCREEN FROM THE SIDEBAR, AND IT SPOKE TO THEM AS
   * THE STUDENT — «رصيدي · حصصك المتبقّية عند كل معلّم» over an account that
   * holds no credits, and a «أوافق على شروط…» card that would have recorded the
   * GUARDIAN agreeing to owe for themselves (`GET/POST /billing/consents`
   * without a `student` resolves the signer as the student). A child's balance
   * lives on that child's own dashboard (`ChildBalanceCard`), and buying for
   * them is `/billing/purchase`, which already asks which child. So a guardian
   * gets those two ways out and none of the student's sections. Agreeing to the
   * deferred-payment terms ON BEHALF of a child lives on that same dashboard
   * card, where `TermsConsentCard` carries the child's uuid.
   */
  // The shell layout renders nothing until `user` is known, so it is never null here.
  if (dashboardAudience(user) === "guardian") {
    return (
      <div className="space-y-8">
        <PageHeader
          Icon={CreditsIcon}
          title={navLabel("/billing", user) ?? "شراء حصص لأبنائي"}
          description="رصيد كلّ ابن يظهر في لوحته، ومن هنا تشتري له حصصاً."
        />

        <EmptyState
          title="رصيد أبنائك في لوحة كلّ ابن"
          description="افتح لوحة ابنك لترى حصصه المتبقّية عند كل معلّم، أو اشترِ له حصصاً الآن."
          action={
            <Button variant="primary" href="/billing/purchase">
              شراء حصص لابنك
            </Button>
          }
        />
      </div>
    );
  }

  return <StudentBilling />;
}

/** The student's own balances, consents and ledger. */
function StudentBilling() {
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
      <PageHeader
        Icon={CreditsIcon}
        title="رصيدي"
        description="حصصك المتبقّية عند كل معلّم، وسجلّ كل حركة عليها."
      />

      {error !== "" && (
        <Alert tone="danger" title="تعذّرت العملية">
          {error}
        </Alert>
      )}

      {/* Above the balances, and it renders nothing when nothing is
          outstanding — a student who has signed never sees it, and it comes
          back by itself the day new terms are published. */}
      <TermsConsentCard />

      <section aria-labelledby="balances-heading" className="space-y-4">
        <SectionHeading id="balances-heading" Icon={WalletIcon} title="الأرصدة" />

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
            /* The way OUT of the empty state, and it was missing.
               The only link to `/billing/purchase` lived inside BalanceSummary,
               which renders only when a balance already exists — so the student
               this message is written for was the one person who could not act
               on it. Not shown on the error branch: the balances failed to load,
               so "you have none" is not something we know. */
            action={
              failed ? undefined : (
                <Button variant="primary" href="/billing/purchase">
                  شراء حصص
                </Button>
              )
            }
          />
        ) : (
          <BalanceSummary balances={balances} />
        )}
      </section>

      <section aria-labelledby="ledger-heading" className="space-y-4">
        <SectionHeading id="ledger-heading" Icon={ListIcon} title="سجلّ الحركة" />

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
