"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
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
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");

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
    <div className="mx-auto max-w-2xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-ink-muted">
          {unread > 0
            ? `لديك ${unread.toLocaleString("ar-EG")} إشعاراً غير مقروء`
            : "لا إشعارات غير مقروءة"}
        </p>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              setPage(1);
              setOnlyUnread((value) => !value);
            }}
          >
            {onlyUnread ? "عرض الكل" : "غير المقروء فقط"}
          </Button>
          <Button size="sm" onClick={markAllRead} disabled={unread === 0}>
            تعليم الكل مقروءاً
          </Button>
        </div>
      </div>

      {error && <Alert tone="danger" title={error} />}

      {loading && <p className="text-ink-muted">جارٍ التحميل…</p>}

      {!loading && items.length === 0 && (
        <Card>
          <p className="py-6 text-center text-ink-muted">لا توجد إشعارات بعد.</p>
        </Card>
      )}

      <ul className="space-y-3">
        {items.map((item) => {
          const isUnread = item.read_at === null;

          return (
            <li key={item.uuid}>
              <Card padding="sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="mb-1 flex flex-wrap items-center gap-x-2 text-xs text-ink-muted">
                      {isUnread && (
                        <span
                          aria-hidden="true"
                          className="inline-block h-2 w-2 shrink-0 rounded-full bg-primary"
                        />
                      )}
                      <span>{item.type_label}</span>
                      {item.workspace && <span>· {item.workspace.name}</span>}
                      {item.subject && <span>· {item.subject.name}</span>}
                    </p>
                    <p className="font-semibold text-ink">
                      {item.title}
                      {isUnread && <span className="sr-only"> (غير مقروء)</span>}
                    </p>
                    <p className="mt-1 text-sm text-ink-muted">{item.body}</p>
                    <p className="mt-2 text-xs text-ink-muted">{formatDate(item.created_at)}</p>
                  </div>

                  {isUnread && (
                    <Button variant="ghost" size="sm" onClick={() => markRead(item.uuid)}>
                      تعليم كمقروء
                    </Button>
                  )}
                </div>

                {item.action_url && (
                  <Link
                    href={item.action_url}
                    onClick={() => isUnread && markRead(item.uuid)}
                    className="mt-3 inline-block rounded text-sm font-semibold text-primary-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    الانتقال
                  </Link>
                )}
              </Card>
            </li>
          );
        })}
      </ul>

      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-3">
          <Button
            variant="secondary"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((value) => value - 1)}
          >
            السابق
          </Button>
          <span className="text-sm text-ink-muted">
            {page.toLocaleString("ar-EG")} / {lastPage.toLocaleString("ar-EG")}
          </span>
          <Button
            variant="secondary"
            size="sm"
            disabled={page >= lastPage}
            onClick={() => setPage((value) => value + 1)}
          >
            التالي
          </Button>
        </div>
      )}
    </div>
  );
}
