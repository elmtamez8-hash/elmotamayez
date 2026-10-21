"use client";

import { useCallback, useEffect, useState } from "react";

import { CoursesIcon } from "@/components/icons";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { plans, type Subscription } from "@/lib/plans";
import type { ChildCardProps } from "./ChildCardProps";
import { DashboardCard, ROW_CLASS, RowIcon } from "./DashboardCard";

/**
 * What the child is subscribed to, and until when — the parent journey
 * (2026-10-21) found a dashboard with sessions, attendance and balances but no
 * answer to «ما الذي اشتركتُ فيه لابني؟», so a guardian could pay twice for a
 * month the child already held. Live rows only: a finished one asks nothing.
 */
export function ChildSubscriptionsCard({ studentUuid, studentName }: ChildCardProps) {
  const [rows, setRows] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    plans
      .mine(studentUuid)
      .then((result) => setRows((result.data ?? []).filter((row) => row.status === "active")))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, [studentUuid]);

  useEffect(load, [load]);

  return (
    <DashboardCard
      title={`اشتراكات ${studentName}`}
      Icon={CoursesIcon}
      href="/orders"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">لا اشتراك ساري لابنك الآن.</p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {rows.map((row) => (
          <li key={row.uuid} className={ROW_CLASS}>
            <RowIcon icon={<CoursesIcon className="h-4 w-4" />} />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-ink">{row.plan_title ?? "اشتراك"}</p>
              {row.teacher_name !== null && <p className="text-xs text-ink-muted">{row.teacher_name}</p>}
              <p className="mt-1 text-sm text-ink">ساري حتى {formatDate(row.ends_on)}</p>
            </div>
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
