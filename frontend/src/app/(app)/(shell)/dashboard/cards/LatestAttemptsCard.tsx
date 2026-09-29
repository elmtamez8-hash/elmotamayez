"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { grading, type StaffAttemptRow } from "@/lib/grading";
import { formatDateTime, statusLabel, statusTone } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import { can, P } from "@/lib/permissions";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";
import { Badge } from "@/components/ui/Badge";
import { ExamIcon } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import { isRefusal } from "./shared-read";

const SHOWN = 5;

/**
 * آخرُ أوراقِ الاختباراتِ التي سلّمَها الطلاب — مُصحَّحةً أو بانتظارِ التصحيح.
 *
 * ⚠️ **الحراسةُ `attempts.view.all` لا `grading.perform`**: قراءةُ النتائجِ غيرُ
 * تصحيحِها، ومساعدٌ يقرأُ ولا يُصحِّحُ يرى البطاقةَ. والخادمُ يحصرُ المساعدَ
 * المقيَّدَ في أوراقِ كورساتِه وحدَها، فلا تصفيةَ هنا.
 *
 * ⚠️ **لا «عرض الكل»**: لا شاشةَ لقائمةِ المحاولاتِ بعد. والصفُّ لا يكونُ رابطاً
 * إلّا لورقةٍ **بانتظارِ التصحيح** ولقارئٍ يملكُ `grading.perform` — صفحةُ
 * `‎/manage/grading/[uuid]` محروسةٌ به وتعرضُ أسئلةَ المقالِ وحدَها، فورقةُ
 * اختيارٍ من متعدّدٍ مُصحَّحةٌ كانت ستفتحُ صفحةً فارغة.
 *
 * ⚠️ و**لا قراءةَ بلا مكانِ عمل** (`teachesOnPlatform`)، والاسمُ **غائبٌ لا
 * فارغ** حينَ يكونُ التصحيحُ مجهولَ الهويّة — الخادمُ لا يُرسِلُه أصلاً.
 */
export function LatestAttemptsCard() {
  const { user } = useAuth();
  const zone = useViewerTimeZone();
  const granted = can(user, P.attemptsViewAll) && teachesOnPlatform(user);
  const grades = can(user, P.gradingPerform);

  const [rows, setRows] = useState<StaffAttemptRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refused, setRefused] = useState(false);

  const load = useCallback(() => {
    if (!granted) return;

    setLoading(true);
    setError(null);

    grading
      .latestAttempts(SHOWN)
      .then((result) => setRows((result.data ?? []).slice(0, SHOWN)))
      .catch((err) => (isRefusal(err) ? setRefused(true) : setError(userMessage(err))))
      .finally(() => setLoading(false));
  }, [granted]);

  useEffect(load, [load]);

  if (!granted || refused) return null;

  return (
    <DashboardCard
      title="آخر المحاولات"
      Icon={ExamIcon}
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && rows.length === 0 ? (
          <p className="text-sm text-ink-muted">لم يسلّم أحد اختباراً بعد.</p>
        ) : null
      }
    >
      <ul className="space-y-2">
        {rows.map((row) => {
          const body = <AttemptRowBody row={row} zone={zone} />;
          const rowClass = "flex items-center justify-between gap-3 rounded-lg border border-line p-3";

          return (
            <li key={row.uuid}>
              {grades && row.status === "pending_grading" ? (
                <Link
                  href={`/manage/grading/${row.uuid}`}
                  className={`${rowClass} transition hover:border-primary/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary`}
                >
                  {body}
                </Link>
              ) : (
                <div className={rowClass}>{body}</div>
              )}
            </li>
          );
        })}
      </ul>
    </DashboardCard>
  );
}

function AttemptRowBody({ row, zone }: { row: StaffAttemptRow; zone: string }) {
  const graded = row.status === "graded";

  return (
    <>
      <span className="min-w-0">
        {/* بلا اسمٍ بديل حينَ يغيبُ الطالب: التصحيحُ المجهولُ لا يُكتَبُ مكانَه شيء. */}
        <span className="block truncate text-sm font-medium text-ink">
          {[row.student?.name, row.exam?.title].filter(Boolean).join(" · ") || "—"}
        </span>
        <span className="block truncate text-xs text-ink-muted">
          {row.course !== null && <>{row.course.title} · </>}
          {formatDateTime(row.submitted_at, zone)}
        </span>
      </span>
      {graded ? (
        <span className="flex shrink-0 items-center gap-2">
          {row.percentage !== null && (
            <span className="text-sm font-semibold text-ink">
              <bdi>{arabicNumber(row.percentage)}٪</bdi>
            </span>
          )}
          {row.passed !== null && (
            <Badge tone={statusTone(row.passed ? "passed" : "failed")}>
              {statusLabel(row.passed ? "passed" : "failed")}
            </Badge>
          )}
        </span>
      ) : (
        <Badge tone="warning">بانتظار التصحيح</Badge>
      )}
    </>
  );
}
