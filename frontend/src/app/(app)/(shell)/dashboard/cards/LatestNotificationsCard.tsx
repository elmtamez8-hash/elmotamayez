"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import {
  NOTIFICATIONS_CHANGED,
  notifications,
  type NotificationItem,
} from "@/lib/notifications";
import { DashboardCard } from "./DashboardCard";

/**
 * آخرُ خمسةِ إشعارات، غيرُ المقروءِ أوّلاً.
 *
 * ⚠️ **لا نبضَ دوريّاً من هنا** (`FR-010` · `R6`). الجرسُ في الترويسةِ ينبضُ كلَّ
 * ستّينَ ثانيةً أصلاً وهو نبضُ الجلسةِ المسجَّلُ في `CLAUDE.md`؛ ونبضٌ ثانٍ على
 * اللوحةِ يضاعفُ الطلباتِ ولا يُقدِّمُ خبراً جديداً. وما تفعلُه هذه البطاقةُ
 * بدلاً منه أنّها تستمعُ إلى `NOTIFICATIONS_CHANGED` — الحدثُ الذي تنشرُه طبقةُ
 * الطلبِ عندَ كلِّ «علِّم كمقروء»، وتعليقُ ذلك الملفِّ يقولُ إنّه يُعلِنُ «اذهبْ
 * واسأل» ولا يحملُ الرقم. فالبطاقةُ تُعيدُ الجلبَ لأنّ شيئاً تغيَّرَ فعلاً.
 *
 * ⚠️ والترتيبُ هنا لا في الخادم: `/notifications` يُرتِّبُ بالأحدثِ أوّلاً وهو
 * الترتيبُ الصحيحُ لمركزِ الإشعارات. أمّا بطاقةٌ من خمسةِ صفوفٍ فسؤالُها «ما الذي
 * لم أقرأْه؟»، وخمسةُ مقروءاتٍ جديدةٍ تدفعُ خبراً لم يُقرَأْ خارجَ الشاشة.
 */
export function LatestNotificationsCard() {
  const [rows, setRows] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setError(null);

    notifications
      .list()
      .then((page) => setRows(page.data ?? []))
      .catch((err) => setError(userMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    // ⚠️ الحدثُ على `window` هو العقدُ نفسُه الذي يقرؤُه الجرس. ولا مؤقّتَ هنا.
    window.addEventListener(NOTIFICATIONS_CHANGED, load);

    return () => window.removeEventListener(NOTIFICATIONS_CHANGED, load);
  }, [load]);

  const latest = [...rows]
    .sort((a, b) => Number(a.read_at !== null) - Number(b.read_at !== null))
    .slice(0, 5);

  return (
    <DashboardCard
      title="آخر الإشعارات"
      href="/notifications"
      loading={loading}
      error={error}
      onRetry={load}
      empty={
        !loading && error === null && latest.length === 0 ? (
          <p className="text-sm text-ink-muted">لا إشعارات بعد.</p>
        ) : null
      }
    >
      <ul className="space-y-3">
        {latest.map((row) => (
          <li key={row.uuid} className="rounded-lg border border-line p-3">
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm font-medium text-ink">
                {row.action_url === null ? (
                  row.title
                ) : (
                  <Link
                    href={row.action_url}
                    className="rounded hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    {row.title}
                  </Link>
                )}
              </p>
              {/* غيرُ المقروءِ يُعلَّمُ بنصٍّ كذلك لا بلونٍ وحدَه: نقطةٌ ملوّنةٌ
                  بلا اسمٍ لا تقولُ شيئاً لقارئِ الشاشة. */}
              {row.read_at === null && (
                <span className="shrink-0 text-xs font-medium text-primary-ink">جديد</span>
              )}
            </div>
            <p className="text-xs text-ink-muted">{formatDateTime(row.created_at)}</p>
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
