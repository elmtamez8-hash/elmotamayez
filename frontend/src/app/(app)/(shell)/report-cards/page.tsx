"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { family, type GuardianRelation } from "@/lib/notifications";
import { arabicDecimal } from "@/lib/numerals";
import { cardPeriodLabel, reportCards, type ReportCard } from "@/lib/reviews";

/**
 * The student's cumulative report cards — and a guardian's view of a child's
 * (spec 010 · US5 · FR-039, FR-040).
 *
 * ⚠️ THE SAME TWO-READER SHAPE AS `/reviews`, and the child picker is not
 * optional decoration: without it the guardian half of FR-040 is an endpoint
 * with no door. That defect shipped once in Phase 6 and is not repeated here.
 *
 * ⚠️ AND «—» WHERE THERE IS NO NUMBER, NEVER «٠٪». A card whose teachers
 * produced no grade at all is not a card reporting a grade of zero, and the
 * person reading the difference is the student's parent.
 */
export default function ReportCardsPage() {
  const { user } = useAuth();
  const isGuardian = user?.platform_role === "parent";

  const [rows, setRows] = useState<ReportCard[]>([]);
  const [children, setChildren] = useState<GuardianRelation[]>([]);
  const [child, setChild] = useState<string>("");
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  useEffect(() => {
    if (!isGuardian) return;

    family
      .list()
      .then((response) => {
        // Only rows carrying a `student_uuid`: a child linked by NAME has no
        // account, and therefore no card to ask for.
        const linked = (response.data ?? []).filter(
          (relation) => relation.status === "active" && relation.student_uuid !== undefined,
        );

        setChildren(linked);
        setChild(linked[0]?.student_uuid ?? "");
      })
      .catch(() => setChildren([]));
  }, [isGuardian]);

  const load = useCallback(() => {
    if (isGuardian && child === "") {
      setRows([]);
      setState("ready");

      return;
    }

    setState("loading");

    reportCards
      .mine(isGuardian ? child : undefined)
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, [isGuardian, child]);

  useEffect(load, [load]);

  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">كشف التقديرات</h1>
        <p className="mt-1 text-sm text-ink-muted">
          {isGuardian
            ? "تقدير ابنك في كلّ فترة، بمساهمة كلّ مدرّس على حدة."
            : "تقديرك في كلّ فترة، بمساهمة كلّ مدرّس على حدة."}
        </p>
      </header>

      {isGuardian && children.length > 1 && (
        <SelectField
          id="child"
          label="الابن/الابنة"
          value={child}
          onChange={setChild}
          options={children.map((relation) => ({
            value: relation.student_uuid ?? "",
            label: relation.student_name,
          }))}
        />
      )}

      {isGuardian && children.length === 0 ? (
        <EmptyState
          title="لا يوجد ابن مرتبط بحساب"
          description="اربط ابنك بحسابه من صفحة المرتبطين ليظهر كشفه هنا."
        />
      ) : state === "loading" ? (
        <RowsSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          title="لا يوجد كشف بعد"
          description="يصدر الكشف في مطلع كلّ شهر عن الشهر الذي سبقه."
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((card) => (
            <li key={card.uuid}>
              <Link
                href={`/report-cards/${card.uuid}`}
                className="block rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              >
                <Card as="article">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="font-semibold text-ink">{cardPeriodLabel(card)}</p>
                    <p className="text-lg font-bold text-ink">
                      {card.overall_pct === null ? "—" : `${arabicDecimal(card.overall_pct)}٪`}
                    </p>
                  </div>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
