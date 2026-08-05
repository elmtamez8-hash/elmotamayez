"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { sessions, type AuthSession } from "@/lib/sessions";

/**
 * Where a student sees which devices are signed in, and ends any of them.
 *
 * This is the answer to the account that was shared and then regretted: the
 * device limit stops the sharing continuing, but only this screen lets the owner
 * cut a session they did not start, without changing their password and without
 * asking anyone.
 */
export default function SecuritySettingsPage() {
  const [list, setList] = useState<AuthSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [ending, setEnding] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    sessions
      .list()
      .then((res) => setList(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const end = async (session: AuthSession) => {
    setError("");
    setEnding(session.uuid);

    try {
      await sessions.end(session.uuid);
      // Ending the current session deletes this browser's own token, so the
      // reload below 401s and the handler in lib/api.ts signs it out properly.
      // No special case here: the server decides, and it already has.
      setList((current) => current.filter((item) => item.uuid !== session.uuid));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setEnding(null);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">الأجهزة والجلسات</h2>
        <p className="mt-2 text-sm text-ink-muted">
          هذه هي الأجهزة التي سُجِّل الدخول إلى حسابك منها الآن. إن رأيت جهازاً لا
          تعرفه، أنهِ جلسته ثم غيّر كلمة مرورك.
        </p>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : list.length === 0 ? (
        <EmptyState
          title="لا توجد جلسات نشطة"
          description="لا يوجد جهاز مسجَّل دخوله إلى حسابك غير هذا الجهاز."
        />
      ) : (
        <ul className="space-y-3">
          {list.map((session) => (
            <li key={session.uuid}>
              <Card as="article" padding="sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-ink">
                        {session.device.label}
                      </span>
                      {session.is_current && <Badge tone="success">هذا الجهاز</Badge>}
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      آخر نشاط: {formatDate(session.last_active_at)} · بدأت في{" "}
                      {formatDate(session.created_at)}
                    </p>
                  </div>

                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => end(session)}
                    loading={ending === session.uuid}
                    loadingLabel="جارٍ الإنهاء…"
                  >
                    {session.is_current ? "سجّل الخروج من هنا" : "أنهِ الجلسة"}
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
