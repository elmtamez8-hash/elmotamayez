"use client";

import { useCallback, useEffect, useState } from "react";

import { FocusTimer } from "@/components/gamification/FocusTimer";
import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { gamification, type Progress } from "@/lib/gamification";

/**
 * Everything this student has earned, in one place (FR-041).
 *
 * ⚠️ COINS ARE LISTED PER TEACHER AND NEVER SUMMED. A total would be a number the
 * student can see and cannot spend: a purse belongs to one teacher, so the shop
 * refuses it on the first attempt. The API sends no total for the same reason.
 */
export default function ProgressPage() {
  const [progress, setProgress] = useState<Progress | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    gamification
      .me()
      .then(setProgress)
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton />;
  if (error !== null) return <ErrorState description={error} onRetry={load} />;
  if (progress === null) return null;

  const toNext =
    progress.next_level_xp === null ? null : progress.next_level_xp - progress.xp;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">تقدّمي</h1>
        <p className="text-sm text-ink-muted">
          خبرتك ومستواك وسلسلتك وشاراتك، عبر كلّ من تدرس عندهم.
        </p>
      </header>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <p className="text-sm text-ink-muted">الخبرة</p>
          <p className="text-2xl font-bold text-ink">
            <bdi>{progress.xp}</bdi>
          </p>
        </Card>
        <Card>
          <p className="text-sm text-ink-muted">المستوى</p>
          <p className="text-2xl font-bold text-ink">
            {progress.level_name ?? <bdi>{progress.level}</bdi>}
          </p>
          {toNext !== null && (
            <p className="text-xs text-ink-muted">
              يتبقّى <bdi>{toNext}</bdi> للمستوى التالي
            </p>
          )}
        </Card>
        <Card>
          <p className="text-sm text-ink-muted">السلسلة</p>
          <p className="text-2xl font-bold text-ink">
            <bdi>{progress.current_streak}</bdi> يوماً
          </p>
          <p className="text-xs text-ink-muted">
            أفضل رقم: <bdi>{progress.best_streak}</bdi>
          </p>
        </Card>
        <Card>
          <p className="text-sm text-ink-muted">دروع الحماية</p>
          <p className="text-2xl font-bold text-ink">
            <bdi>{progress.shields}</bdi>
          </p>
          <p className="text-xs text-ink-muted">يحفظ سلسلتك عند انقطاع يومٍ واحد.</p>
        </Card>
      </div>

      <FocusTimer onFinished={load} />

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">عملاتي</h2>
        {progress.coin_balances.length === 0 ? (
          <EmptyState title="لا عملات بعد" description="تُكتسب العملات بحضورك وحلّ واجباتك." />
        ) : (
          <ul className="space-y-2">
            {progress.coin_balances.map((purse) => (
              <li
                key={purse.workspace_uuid ?? purse.teacher_name}
                className="flex items-center justify-between rounded-lg border border-line px-3 py-2"
              >
                <span className="text-sm text-ink">{purse.teacher_name}</span>
                <span className="text-sm font-semibold text-ink">
                  <bdi>{purse.coins}</bdi>
                </span>
              </li>
            ))}
          </ul>
        )}
        {/* Said out loud rather than left to be discovered at the shop. */}
        <p className="mt-3 text-xs text-ink-muted">
          عملاتك عند كلّ مدرّسٍ تُنفَق في متجره وحده.
        </p>
      </Card>

      <Card>
        <h2 className="mb-3 text-base font-semibold text-ink">شاراتي</h2>
        {progress.badges.length === 0 ? (
          <EmptyState
            title="لا شارات بعد"
            description="تُمنح الشارات على إنجازاتٍ محدّدة، كالمواظبة أو بلوغ مستوى."
          />
        ) : (
          <ul className="flex flex-wrap gap-2">
            {progress.badges.map((badge) => (
              <li key={badge.key}>
                <Badge tone="success">{badge.name}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
