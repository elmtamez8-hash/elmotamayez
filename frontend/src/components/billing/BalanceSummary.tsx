import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { formatCredits, type CreditBalance } from "@/lib/billing";

/**
 * One card per course, never a total.
 *
 * Summing across courses is a wrong answer rather than a shorter one: +10 in
 * maths and −6 in physics reads as +4 and unblocked, while withholding is decided
 * per course precisely so the paid-up course stays open.
 *
 * ⚠️ No money appears here because none arrives. The API sends credits alone
 * (FR-021د), and a component that formatted a price would have nothing to
 * format.
 */
export function BalanceSummary({ balances }: { balances: CreditBalance[] }) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2">
      {balances.map((balance) => (
        <li key={balance.uuid}>
          <Card as="article">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h3 className="truncate text-base font-semibold text-ink">
                  {balance.course.title}
                </h3>
                <p className="mt-1 truncate text-sm text-ink-muted">
                  {balance.course.teacher_name}
                </p>
              </div>

              {/* The label carries the meaning; the tone is emphasis only. */}
              {balance.is_withheld ? (
                <Badge tone="danger">موقوف</Badge>
              ) : (
                <Badge tone="success">متاح</Badge>
              )}
            </div>

            <p className="mt-4 text-3xl font-bold text-ink">
              {/* bdi, because a credit count sits inside an Arabic sentence and
                  the bidirectional algorithm would otherwise put a leading digit
                  on the wrong side of the words around it. */}
              <bdi>{formatCredits(balance.remaining_credits)}</bdi>
            </p>
            <p className="text-sm text-ink-muted">حصة متبقّية</p>

            <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-line pt-4 text-sm">
              <div>
                <dt className="text-ink-muted">المشترى</dt>
                <dd className="font-medium text-ink">
                  <bdi>{formatCredits(balance.purchased_credits)}</bdi>
                </dd>
              </div>
              <div>
                <dt className="text-ink-muted">المستهلَك</dt>
                <dd className="font-medium text-ink">
                  <bdi>{formatCredits(balance.consumed_credits)}</bdi>
                </dd>
              </div>
            </dl>

            {balance.credit_limit_credits > 0 && (
              <p className="mt-3 text-xs text-ink-muted">
                يسمح لك معلّمك بالحجز حتى{" "}
                <bdi>{balance.credit_limit_credits.toLocaleString("ar-EG")}</bdi>{" "}
                حصة قبل السداد.
              </p>
            )}

            {/*
              What a withheld student is owed: the reason, the amount, and the way
              out — never a bare error (FR-032 · US5/7). It sits on the card of the
              course that stopped, because withholding is per course and a banner
              at the top of the page would read as though everything had stopped.

              ⚠️ THE NUMBER IS SENT, AND IT USED TO BE DERIVED HERE. The comment
              that stood in this place argued that `credits_needed` would be "a
              second copy of one subtraction, wrong the first time the two
              disagree" — and it was exactly right about the danger and exactly
              wrong about which copy this was. The deficit is the distance to the
              EFFECTIVE floor, which depends on the billing mode, an open exam
              window and a current terms consent. None of the three is in this
              payload, so the browser could not be right during an exam window,
              nor for any indebted student on the day new terms are published:
              the card said "buy 1", the booking gate demanded three.
            */}
            {balance.is_withheld && (
              <Alert tone="warning" title="توقّف الحجز في هذا الكورس">
                رصيدك لم يعد يكفي لحجز حصة جديدة. تحتاج{" "}
                <bdi>{balance.credits_needed.toLocaleString("ar-EG")}</bdi>{" "}
                حصة على الأقل لاستئنافه. حصصك المحجوزة سابقاً وتسجيلك في الكورس لا
                يتأثّران، ويعود الحجز فور اعتماد الدفع بلا أي إجراء منك.
              </Alert>
            )}

            {/* The way IN to buying more, and it belongs here rather than on one
                page-level button: the price is per course, so "buy credits" with
                no course chosen is a question the next screen has to ask again.
                Carrying the uuid from the card the student is already looking at
                answers it before it is asked. */}
            <div className="mt-4">
              <Button
                variant={balance.is_withheld ? "primary" : "secondary"}
                href={`/billing/purchase?course=${encodeURIComponent(balance.course.uuid)}`}
              >
                شراء أرصدة
              </Button>
            </div>
          </Card>
        </li>
      ))}
    </ul>
  );
}
