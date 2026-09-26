"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { gamification, type Progress } from "@/lib/gamification";
import { counted, NOUNS } from "@/lib/labels";
import { can, P } from "@/lib/permissions";

/**
 * One student's points, level, streak and badges, as their teacher reads them
 * (`GET /gamification/students/{user}` · FR-042).
 *
 * ⚠️ THE ENDPOINT HAD NO CALLER, and its permission guarded nothing anybody
 * could reach. A teacher could see a student's rank on the class board and
 * nothing of the student behind it.
 *
 * ⚠️ NO COINS. `coin_balances` is every teacher's purse for this student —
 * other academies' included — and a teacher has no business reading what a
 * student holds with somebody else. The student's own `/progress` shows them.
 *
 * ⚠️ A READER WITHOUT `progress.view.student` IS TOLD SO, NOT SHOWN A 403.
 * The request is never made: the panel says which permission is missing and
 * who can grant it, the same answer the special-arrangements page gives an
 * assistant who may not read the class list.
 */
export function StudentProgressPanel({ studentUuid }: { studentUuid: string }) {
  const { user } = useAuth();
  const allowed = can(user, P.progressViewStudent);

  const [progress, setProgress] = useState<Progress | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!allowed) return;

    let cancelled = false;

    gamification
      .student(studentUuid)
      .then((p) => {
        if (!cancelled) setProgress(p);
      })
      .catch((e: unknown) => {
        if (!cancelled) setError(userMessage(e));
      });

    return () => {
      cancelled = true;
    };
  }, [allowed, studentUuid]);

  if (!allowed) {
    return (
      <Alert tone="info" title="تقدّم الطالب غير متاح لحسابك">
        عرض نقاط الطالب ومستواه وشاراته يحتاج صلاحية عرض تقدّم الطلاب، وحسابك لا يملكها. اطلب من المدرّس صاحب الحساب
        منحها لك.
      </Alert>
    );
  }

  if (error !== null) return <Alert tone="danger" title="تعذّر تحميل تقدّم الطالب">{error}</Alert>;

  if (progress === null) {
    return (
      <div className="space-y-2" aria-hidden>
        <div className="h-3 w-32 animate-pulse rounded bg-primary-soft" />
        <div className="h-3 w-24 animate-pulse rounded bg-primary-soft" />
      </div>
    );
  }

  return (
    <div className="space-y-2 text-xs" aria-label="تقدّم الطالب">
      <dl className="grid grid-cols-3 gap-2">
        <div>
          <dt className="text-ink-muted">المستوى</dt>
          <dd className="font-semibold text-ink">
            {progress.level_name ?? <bdi>{progress.level}</bdi>}
          </dd>
        </div>
        <div>
          <dt className="text-ink-muted">الخبرة</dt>
          <dd className="font-semibold text-ink">
            <bdi>{progress.xp}</bdi>
          </dd>
        </div>
        <div>
          <dt className="text-ink-muted">السلسلة</dt>
          <dd className="font-semibold text-ink">
            {counted(progress.current_streak, { ...NOUNS.days, zero: "لم تبدأ بعد" })}
          </dd>
        </div>
      </dl>

      {progress.badges.length === 0 ? (
        <p className="text-ink-muted">لا شارات بعد.</p>
      ) : (
        <ul className="flex flex-wrap gap-1.5" aria-label="الشارات">
          {progress.badges.map((badge) => (
            <li key={badge.key}>
              <Badge tone="success">{badge.name}</Badge>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
