"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { offboardingQueue, type TeacherOffboarding } from "@/lib/compliance";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

/**
 * The exits waiting on money or on a date (FR-032 · SC-013).
 *
 * ⚠️ THE WAIT IS THE WHOLE SCREEN. `settlement_pending` exists as a STATE rather
 * than a flag beside `requested` precisely so that "this teacher cannot leave yet,
 * and here is why" is visible to somebody — a state no screen can reach is a
 * comment. That is also why the platform Action got an endpoint at all rather than
 * following the `billing.credits.adjust` precedent of having none.
 */
export default function OffboardingQueuePage() {
  const [rows, setRows] = useState<TeacherOffboarding[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(() => {
    offboardingQueue
      .list()
      .then((response) => {
        setRows(response.data);
        setError(null);
      })
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  async function execute(uuid: string) {
    setBusy(uuid);

    try {
      await offboardingQueue.execute(uuid);
      setError(null);
      load();
    } catch (cause: unknown) {
      /*
       * ⚠️ THE SERVER'S OWN SENTENCE, NOT ONE WRITTEN HERE. The refusal names what
       * is missing — money outstanding, or a notice that has not run out — and the
       * two are acted on differently. A generic "تعذّر الإجراء" would leave the
       * officer pressing the same button waiting for a different answer.
       */
      setError(userMessage(cause));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">خروج المدرّسين</h1>
        <p className="text-sm text-ink-muted">
          طلباتُ إنهاء النشاط، وما ينتظره كلٌّ منها قبل أن يكتمل.
        </p>
      </header>

      {error !== null && (
        <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
          {error}
        </Alert>
      )}

      {loading ? (
        <Card>
          <p className="text-sm text-ink-muted">جارٍ التحميل…</p>
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <p className="text-sm text-ink-muted">لا طلبات خروجٍ مفتوحة.</p>
        </Card>
      ) : (
        <div className="space-y-3">
          {rows.map((row) => (
            <Card key={row.uuid}>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-ink">{row.status_label}</p>
                  <p className="text-sm text-ink-muted">
                    المستحقّات: {row.dues_cleared ? "حُسمت" : "قيد الحسم"}
                    {row.notice_ends_at !== null && ` · تنتهي المهلة ${formatDate(row.notice_ends_at)}`}
                  </p>
                </div>

                {/*
                  Offered whatever the state, because the SERVER decides: the
                  refusal is the sentence that tells the officer which of the two
                  conditions is still open, and hiding the button would replace a
                  reason with silence.
                */}
                <Button
                  variant="danger"
                  onClick={() => execute(row.uuid)}
                  loading={busy === row.uuid}
                  loadingLabel="جارٍ التنفيذ…"
                >
                  أتمِمِ الخروج
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
