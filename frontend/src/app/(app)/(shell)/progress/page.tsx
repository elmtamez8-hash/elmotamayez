"use client";

import { useCallback, useEffect, useState } from "react";

import { FocusTimer } from "@/components/gamification/FocusTimer";
import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatTile } from "@/components/ui/StatTile";
import {
  ProgressIcon,
  ShieldIcon,
  SparkIcon,
  StarIcon,
  VerifiedBadgeIcon,
  WalletIcon,
} from "@/components/icons";
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
      <PageHeader
        Icon={ProgressIcon}
        title="تقدّمي"
        description="خبرتك ومستواك وسلسلتك وشاراتك، عبر كلّ من تدرس عندهم."
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label="الخبرة" value={String(progress.xp)} Icon={SparkIcon} />
        <StatTile
          label="المستوى"
          value={progress.level_name ?? String(progress.level)}
          Icon={ProgressIcon}
          emphasis
          hint={
            toNext !== null ? (
              <>
                يتبقّى <bdi>{toNext}</bdi> للمستوى التالي
              </>
            ) : undefined
          }
        />
        <StatTile
          label="السلسلة"
          value={`${progress.current_streak} يوماً`}
          Icon={StarIcon}
          hint={
            <>
              أفضل رقم: <bdi>{progress.best_streak}</bdi>
            </>
          }
        />
        <StatTile
          label="دروع الحماية"
          value={String(progress.shields)}
          Icon={ShieldIcon}
          hint="يحفظ سلسلتك عند انقطاع يومٍ واحد."
        />
      </div>

      <FocusTimer onFinished={load} />

      <Card>
        <div className="mb-3">
          <SectionHeading id="progress-coins" Icon={WalletIcon} title="عملاتي" />
        </div>
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
        <div className="mb-3">
          <SectionHeading id="progress-badges" Icon={VerifiedBadgeIcon} title="شاراتي" />
        </div>
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
