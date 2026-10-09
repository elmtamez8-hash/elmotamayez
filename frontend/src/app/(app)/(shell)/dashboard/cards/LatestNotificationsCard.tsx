"use client";

import { NotificationLink } from "@/components/notifications/NotificationLink";
import { useCallback, useEffect, useState } from "react";

import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";
import {
  NOTIFICATIONS_CHANGED,
  notifications,
  type NotificationItem,
} from "@/lib/notifications";
import { BellIcon } from "@/components/icons";
import { categoryIcon, categoryTone } from "@/components/notifications/categoryIcons";
import { DashboardCard, ROW_CLASS, RowIcon } from "./DashboardCard";

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
      Icon={BellIcon}
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
      <ul className="space-y-2">
        {latest.map((row) => {
          const unread = row.read_at === null;
          const subject = row.category?.key ?? null;

          return (
            <li
              key={row.uuid}
              className={`${ROW_CLASS} ${unread ? "border-primary/30 bg-primary-soft/30" : "hover:border-primary/30"}`}
            >
              {/* أيقونةُ الموضوعِ نفسُها التي ترسمُها صفحةُ الإشعارات
                  (`categoryIcons`)، لا رسمٌ ثانٍ للمعنى نفسِه. */}
              <RowIcon icon={categoryIcon(subject, "h-4 w-4")} tone={categoryTone(subject)} />

              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <p className={`text-sm text-ink ${unread ? "font-bold" : "font-medium"}`}>
                    {row.action_url === null ? (
                      row.title
                    ) : (
                      <NotificationLink
                        href={row.action_url}
                        className="rounded hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                      >
                        {row.title}
                      </NotificationLink>
                    )}
                  </p>
                  {/* غيرُ المقروءِ يُعلَّمُ بنصٍّ كذلك لا بلونٍ وحدَه: نقطةٌ ملوّنةٌ
                      بلا اسمٍ لا تقولُ شيئاً لقارئِ الشاشة. */}
                  {unread && (
                    <span className="shrink-0 rounded-full bg-primary px-2 py-0.5 text-[11px] font-bold text-white">
                      جديد
                    </span>
                  )}
                </div>
                {row.body !== "" && <p className="mt-1 line-clamp-2 text-sm text-ink-muted">{row.body}</p>}
                <p className="mt-1 text-xs text-ink-muted">{formatDateTime(row.created_at)}</p>
              </div>
            </li>
          );
        })}
      </ul>
    </DashboardCard>
  );
}
