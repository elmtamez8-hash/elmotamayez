"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { billing, type CreditBalance } from "@/lib/billing";
import { userMessage } from "@/lib/errors";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import {
  plans as plansApi,
  planDuration,
  SESSION_TYPE_LABELS,
  type Plan,
  type Subscription,
} from "@/lib/plans";

/**
 * The student's subscriptions, and what is on offer (spec 011 · US4).
 *
 * ⚠️ THE TEACHER IS PICKED BY A COURSE, because that is the only identifier a
 * student's payloads carry. No student-facing Resource sends a workspace uuid —
 * the raw tenant key does not travel — so the picker is built from the balances
 * the student already has, exactly as `/billing/purchase` builds its own.
 *
 * ⚠️ AND BUYING ANSWERS WITH AN ORDER, NOT A SUBSCRIPTION. A manual bank
 * transfer takes days, so the screen must say «ستبدأ عند اعتماد الدفعة» rather
 * than showing a month that has not started — the same rule the store's checkout
 * follows, and for the same reason: a page that shows access it has not got
 * generates the support ticket the day after.
 *
 * ⚠️ THE END DATE PRINTED IS THE EFFECTIVE ONE. A freeze moves it, and the expiry
 * notice reads the same column — a card showing the sold date would contradict
 * the message in the bell.
 */
export default function PlansPage() {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [balances, setBalances] = useState<CreditBalance[]>([]);
  const [courseUuid, setCourseUuid] = useState("");
  const [offers, setOffers] = useState<Plan[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [ordered, setOrdered] = useState<string | null>(null);
  const [buying, setBuying] = useState<string | null>(null);

  const load = useCallback(async () => {
    setState("loading");

    try {
      const [mine, myBalances] = await Promise.all([plansApi.mine(), billing.balances()]);

      setSubscriptions(mine.data);
      setBalances(myBalances.data);
      setState("ready");
    } catch (error) {
      setProblem(userMessage(error));
      setState("error");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    if (courseUuid === "") {
      setOffers([]);

      return;
    }

    let live = true;

    plansApi
      .forCourse(courseUuid)
      // An empty list is a real answer — this teacher sells no subscription —
      // and the screen below says so. It is not an error state.
      .then((result) => {
        if (live) setOffers(result.data);
      })
      .catch((error: unknown) => {
        if (live) setProblem(userMessage(error));
      });

    return () => {
      live = false;
    };
  }, [courseUuid]);

  async function buy(plan: Plan) {
    setBuying(plan.uuid);
    setProblem(null);

    try {
      await plansApi.buy(plan.uuid);
      setOrdered(plan.title);
      await load();
    } catch (error) {
      setProblem(userMessage(error));
    } finally {
      setBuying(null);
    }
  }

  if (state === "loading") return <RowsSkeleton />;
  if (state === "error") {
    return <ErrorState description={problem ?? undefined} onRetry={() => void load()} />;
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">اشتراكاتي</h1>
        <p className="mt-1 text-sm text-ink-muted">
          الاشتراك بالمدّة يفتح ما تغطّيه الباقة طوال مدّتها، وحصصه لا تخصم من رصيدك.
        </p>
      </header>

      {problem && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {problem}
        </Alert>
      )}

      {ordered !== null && (
        <Alert tone="info" title="سُجِّل طلبك">
          طلبتَ «{ordered}». ترفع إيصال التحويل من صفحة «الطلبات»، وتبدأ الباقة عند اعتماد
          الدفعة — لا قبله.
        </Alert>
      )}

      <Card>
        <div className="space-y-3">
          <h2 className="text-base font-semibold text-ink">اشتراكاتي الحالية والسابقة</h2>

          {subscriptions.length === 0 ? (
            <EmptyState
              title="لا اشتراك بعد"
              description="اختر مدرّساً من القائمة أدناه لترى ما يعرضه من باقات بالمدّة."
            />
          ) : (
            <ul className="divide-y divide-line">
              {subscriptions.map((row) => (
                <li key={row.uuid} className="flex items-center justify-between gap-4 py-3">
                  <div>
                    <p className="text-sm font-medium text-ink">
                      {row.plan_title ?? "اشتراك"}
                      {row.teacher_name !== null && (
                        <span className="text-ink-muted"> — {row.teacher_name}</span>
                      )}
                    </p>
                    <p className="text-xs text-ink-muted">
                      حتى {formatDate(row.ends_on)}
                      {/* Only when a freeze actually moved it: an identical pair on
                          every row is noise the reader compares and learns nothing
                          from. */}
                      {row.sold_ends_on !== null && " — مُدِّدت بسبب فترة تجميد"}
                    </p>
                  </div>

                  <Badge tone={row.status === "active" ? "success" : "neutral"}>
                    {row.status_label}
                  </Badge>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>

      <Card>
        <div className="space-y-4">
          <h2 className="text-base font-semibold text-ink">باقات المدرّسين</h2>

          <SelectField
            id="plans-course"
            label="اختر الكورس"
            hint="الباقة تُشترى من المدرّس صاحب الكورس، وسعرها يختلف من مدرّس لآخر."
            value={courseUuid}
            onChange={setCourseUuid}
            options={balances.map((balance) => ({
              value: balance.course.uuid,
              label: `${balance.course.title} — ${balance.course.teacher_name}`,
            }))}
            placeholder="اختر كورساً"
          />

          {courseUuid !== "" && offers.length === 0 && (
            <EmptyState
              title="لا باقات بالمدّة عند هذا المدرّس"
              description="يمكنك شراء حصص مفردة أو باقة عدد حصص من صفحة «رصيدي»."
            />
          )}

          {offers.map((plan) => (
            <div
              key={plan.uuid}
              className="flex items-center justify-between gap-4 rounded-xl border border-line p-4"
            >
              <div>
                <p className="text-sm font-medium text-ink">{plan.title}</p>
                <p className="text-xs text-ink-muted">
                  {planDuration(plan.duration_days)} · {SESSION_TYPE_LABELS[plan.session_type]} ·{" "}
                  {plan.coverage_label}
                </p>
              </div>

              <div className="flex items-center gap-3">
                <span className="text-sm font-semibold text-ink">
                  {/* `is_sellable` guarantees a price, but the null check stays:
                      `(number | null)` printed unguarded renders «null». */}
                  {plan.price_minor === null
                    ? "—"
                    : formatMinorMoney(plan.price_minor, plan.currency)}
                </span>

                <Button
                  type="button"
                  onClick={() => void buy(plan)}
                  disabled={buying !== null || !plan.is_sellable}
                >
                  اشترِ
                </Button>
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
