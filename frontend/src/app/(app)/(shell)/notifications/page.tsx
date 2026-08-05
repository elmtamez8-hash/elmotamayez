"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/labels";
import { notifications, type NotificationItem } from "@/lib/notifications";

/**
 * The notification centre — the first place the architecture becomes visible to
 * anyone who is not reading the database.
 */
export default function NotificationsPage() {
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [unread, setUnread] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [onlyUnread, setOnlyUnread] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const result = await notifications.list({ page, unread: onlyUnread });
      setItems(result.data);
      setUnread(result.meta.unread_count);
      setLastPage(result.meta.last_page);
    } catch (err) {
      // Never the raw error: it is an English developer string.
      setError(errorMessage(err, "تعذّر تحميل الإشعارات."));
    } finally {
      setLoading(false);
    }
  }, [page, onlyUnread]);

  useEffect(() => {
    void load();
  }, [load]);

  const markRead = async (uuid: string) => {
    try {
      const { unread_count } = await notifications.markRead(uuid);
      setUnread(unread_count);
      setItems((current) =>
        current.map((item) =>
          item.uuid === uuid ? { ...item, read_at: new Date().toISOString() } : item,
        ),
      );
    } catch (err) {
      setError(errorMessage(err, "تعذّر تعليم الإشعار مقروءاً."));
    }
  };

  const markAllRead = async () => {
    try {
      await notifications.markAllRead();
      setUnread(0);
      setItems((current) =>
        current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })),
      );
    } catch (err) {
      setError(errorMessage(err, "تعذّر تعليم الكل مقروءاً."));
    }
  };

  return (
    <div className="mx-auto max-w-3xl">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-ink-muted">
          {unread > 0 ? `لديك ${unread.toLocaleString("ar-EG")} إشعاراً غير مقروء` : "لا إشعارات غير مقروءة"}
        </p>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => {
              setPage(1);
              setOnlyUnread((value) => !value);
            }}
            className="rounded-lg border border-line px-3 py-1.5 text-sm text-ink transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {onlyUnread ? "عرض الكل" : "غير المقروء فقط"}
          </button>
          <button
            type="button"
            onClick={markAllRead}
            disabled={unread === 0}
            className="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            تعليم الكل مقروءاً
          </button>
        </div>
      </div>

      {error && (
        <p role="alert" className="mb-4 rounded-lg border border-danger bg-surface-raised px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      {loading && <p className="text-ink-muted">جارٍ التحميل…</p>}

      {!loading && items.length === 0 && (
        <p className="rounded-lg border border-line bg-surface-raised px-4 py-10 text-center text-ink-muted">
          لا توجد إشعارات بعد.
        </p>
      )}

      <ul className="space-y-2">
        {items.map((item) => {
          const isUnread = item.read_at === null;

          return (
            <li
              key={item.uuid}
              className={`rounded-lg border p-4 transition ${
                isUnread ? "border-primary bg-primary-soft" : "border-line bg-surface-raised"
              }`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="mb-1 text-xs text-ink-muted">
                    {item.type_label}
                    {item.workspace && ` · ${item.workspace.name}`}
                    {item.subject && ` · ${item.subject.name}`}
                  </p>
                  <p className="font-semibold text-ink">{item.title}</p>
                  <p className="mt-1 text-sm text-ink-muted">{item.body}</p>
                  <p className="mt-2 text-xs text-ink-muted">{formatDate(item.created_at)}</p>
                </div>
                {isUnread && (
                  <button
                    type="button"
                    onClick={() => markRead(item.uuid)}
                    className="shrink-0 rounded-lg border border-line px-2 py-1 text-xs text-ink transition hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    تعليم كمقروء
                  </button>
                )}
              </div>

              {item.action_url && (
                <Link
                  href={item.action_url}
                  onClick={() => isUnread && markRead(item.uuid)}
                  className="mt-3 inline-block rounded text-sm font-medium text-primary-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  الانتقال
                </Link>
              )}
            </li>
          );
        })}
      </ul>

      {lastPage > 1 && (
        <div className="mt-6 flex items-center justify-center gap-3">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => setPage((value) => value - 1)}
            className="rounded-lg border border-line px-3 py-1.5 text-sm text-ink disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            السابق
          </button>
          <span className="text-sm text-ink-muted">
            {page.toLocaleString("ar-EG")} / {lastPage.toLocaleString("ar-EG")}
          </span>
          <button
            type="button"
            disabled={page >= lastPage}
            onClick={() => setPage((value) => value + 1)}
            className="rounded-lg border border-line px-3 py-1.5 text-sm text-ink disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            التالي
          </button>
        </div>
      )}
    </div>
  );
}
