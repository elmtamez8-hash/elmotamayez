"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { CreditsIcon, ScheduleIcon, TagIcon } from "@/components/icons";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { billing, type CreditBalance } from "@/lib/billing";
import { userMessage } from "@/lib/errors";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import {
  plans as plansApi,
  planCheckoutHref,
  planShape,
  SESSION_TYPE_LABELS,
  type Plan,
  type Subscription,
} from "@/lib/plans";
import { subscribe } from "@/lib/subscribe";

/**
 * The student's subscriptions, and what is on offer (spec 011 · US4).
 *
 * ⚠️ THE TEACHER IS PICKED BY A COURSE, because that is the only identifier a
 * student's payloads carry. No student-facing Resource sends a workspace uuid —
 * the raw tenant key does not travel — so the picker is built from the balances
 * the student already has, exactly as `/billing/purchase` builds its own.
 *
 * ⛔ AND BUYING HAPPENS ON `/subscribe`, NOT HERE. «اشترِ» is a link
 * ({@link planCheckoutHref}). This page used to post its own order with
 * `plans.buy`, which sent no `mode` and was refused with 422 on every press from
 * 2026-09-05 until it was removed: one purchase screen builds the whole body and
 * takes the receipt, and a second one beside it is how this one broke unnoticed.
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
  const [courseSlug, setCourseSlug] = useState<string | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);

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

    setCourseSlug(null);

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

    /*
     * The slug, for a group plan's «اشترِ» — see `planCheckoutHref` on why the
     * uuid address loses `?tab=groups`. Swallowed on purpose: without it the
     * link falls back to the uuid and `#groups`, which still works, and a
     * banner about a missing slug would read as a failed purchase.
     */
    subscribe
      .course(courseUuid)
      .then((result) => {
        if (live) setCourseSlug(result.data.slug ?? null);
      })
      .catch(() => undefined);

    return () => {
      live = false;
    };
  }, [courseUuid]);

  if (state === "loading") return <RowsSkeleton />;
  if (state === "error") {
    return <ErrorState description={problem ?? undefined} onRetry={() => void load()} />;
  }

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={CreditsIcon}
        title="اشتراكاتي"
        description="الاشتراك بالمدّة يفتح ما تغطّيه الباقة طوال مدّتها، وحصصه لا تخصم من رصيدك."
      />

      {problem && (
        <Alert tone="danger" title="تعذّر إتمام الطلب">
          {problem}
        </Alert>
      )}


      <Card as="section">
        <div className="space-y-3">
          <SectionHeading id="plans-mine" Icon={ScheduleIcon} title="اشتراكاتي الحالية والسابقة" />

          {subscriptions.length === 0 ? (
            <EmptyState
              title="لا اشتراك بعد"
              description={
                balances.length === 0
                  ? "ابدأ من صفحة مدرّس أو كورس: الاشتراك في مجموعة أو بحصص خاصّة يبدأ من هناك."
                  : "اختر كورساً من القائمة أدناه لترى ما يعرضه مدرّسه من باقات بالمدّة."
              }
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

      <Card as="section">
        <div className="space-y-4">
          <SectionHeading id="plans-offers" Icon={TagIcon} title="باقات المدرّسين" />

          {/*
            ⚠️ THE PICKER IS BUILT FROM THE BALANCES, SO A NEW STUDENT'S IS EMPTY
            — reported 2026-09-26: «اختر كورساً» and nothing under it, below a
            sentence telling them to choose from it, and no way off the page.
            The course that would fill it is bought on its own page (`/subscribe`
            starts there), so the empty answer is a door to the teachers.
          */}
          {balances.length === 0 ? (
            <EmptyState
              title="لم تبدأ مع أي مدرّس بعد"
              description="تظهر هنا باقات مدرّسيك بعد أول اشتراك. تصفّح المدرّسين أو الكورسات، واشترك من صفحة الكورس الذي تريده."
              action={
                <div className="flex flex-wrap justify-center gap-2">
                  <Button href="/teachers">تصفّح المدرّسين</Button>
                  <Button href="/courses" variant="secondary">
                    تصفّح الكورسات
                  </Button>
                </div>
              }
            />
          ) : (
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
          )}

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
                  {/* ⚠️ يُبنى ثمّ يُرشَّح، لا يُوصَلُ بفواصلَ مكتوبةٍ بينَ القيم:
                      `planDuration` تُرجِعُ `null` لمدّةٍ غيرِ معلومة، وفاصلٌ
                      مكتوبٌ بيدِه يتركُ « · » معلّقةً في أوّلِ السطر. */}
                  {[
                    planShape(plan),
                    SESSION_TYPE_LABELS[plan.session_type],
                    plan.coverage_label,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
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

                {plan.is_sellable ? (
                  <Button href={planCheckoutHref(courseUuid, plan, courseSlug)}>اشترِ</Button>
                ) : (
                  <Button type="button" disabled>
                    اشترِ
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
