"use client";

import { useCallback, useEffect, useState } from "react";

import { billing, type CreditBalance } from "@/lib/billing";
import { userMessage } from "@/lib/errors";
import { arabicNumber } from "@/lib/numerals";
import { DashboardCard } from "./DashboardCard";

/**
 * رصيدُ حصصِ الابنِ — سطرٌ لكلِّ كورس.
 *
 * ⚠️ **لا مجموعَ واحداً، ولا رقمَ يجمعُ كورسَين.** الحجبُ لكلِّ كورسٍ على حِدَةٍ
 * بالتصميم، فـ`+10` رياضيات و`−6` فيزياء ليست `+4`: المجموعُ يُظهِرُ سَعَةً عن
 * كورسٍ محجوبٍ لا يستطيعُ الابنُ الحجزَ فيه، ويُخفي الكورسَ الذي عليه أن يُدفَع.
 * والخادمُ لا يُرسِلُ مجموعاً لأنّه لا يوجدُ مجموعٌ صحيح.
 *
 * ⚠️ ولا مبلغَ في أيِّ سطر (`FR-016`): سعرُ الحصّةِ يُحَلُّ منه سعرُ المدرّس.
 */
export function ChildBalanceCard({ studentUuid }: { studentUuid: string }) {
  const [balances, setBalances] = useState<CreditBalance[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    billing
      .childBalances(studentUuid)
      .then((result) => setBalances(result.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [studentUuid]);

  useEffect(load, [load]);

  // المحجوبُ أوّلاً: هو السطرُ الوحيدُ الذي يطلبُ فعلاً من القارئ.
  const rows = [...balances].sort(
    (a, b) => Number(b.is_withheld) - Number(a.is_withheld),
  );

  return (
    <DashboardCard
      title="رصيد حصص ابنك"
      href="/family"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">لا رصيد حصص لابنك بعد.</p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {rows.map((balance) => (
          <li key={balance.uuid} className="rounded-lg border border-line p-3">
            <p className="text-sm font-medium text-ink">{balance.course.title}</p>
            <p className="text-xs text-ink-muted">{balance.course.teacher_name}</p>
            <p className="mt-1 text-sm text-ink">
              الحصص المتبقّية: <bdi>{arabicNumber(balance.remaining_credits)}</bdi>
            </p>
            {balance.is_withheld && (
              <p className="mt-1 text-sm text-danger-ink">
                {/* العددُ من الخادم: الأرضيّةُ الفعليّةُ تعتمدُ على السقفِ ونمطِ
                    الفوترةِ ونافذةِ الاختباراتِ وموافقةٍ سارية — ولا شيءَ من ذلك
                    في هذه الحمولة، فحسابُه هنا يُنتِجُ رقماً يبدو صحيحاً وحدَه. */}
                محجوب — يلزم شراء <bdi>{arabicNumber(balance.credits_needed)}</bdi> حصّة
                لاستئناف الحجز.
              </p>
            )}
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
