"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { complianceQueue, type OfficerDataRequest } from "@/lib/compliance";
import { userMessage } from "@/lib/errors";
import { formatDate, type StatusTone } from "@/lib/labels";

/**
 * The data-protection officer's queue (FR-026 · FR-043).
 *
 * ⚠️ THIS SCREEN IS WHY `store` DOES NOT DISPATCH AN ERASURE. FR-019 promises an
 * announced execution period, which exists so that a destruction nobody can undo is
 * looked at by a person who can weigh a legal hold against it. Without a way in,
 * the execute and refuse endpoints would be decorations and every erasure request
 * ever made would sit `pending` for ever — which is exactly the shape a
 * «coming soon» note leaves behind.
 *
 * ⚠️ AND THE LATE ONES COME FIRST, BECAUSE `due_at` IS A LEGAL DEADLINE. The server
 * orders by it and the whole `(status, due_at)` index exists for this query; the
 * screen shows the date rather than a relative phrase, so an officer reading it at
 * a glance is reading the same value an auditor will.
 */
const STATUS_LABELS: Record<OfficerDataRequest["status"], string> = {
  pending: "بانتظار التنفيذ",
  processing: "قيد التنفيذ",
  completed: "تمّ",
  refused: "مرفوض",
  on_hold: "موقوف بتعليق",
};

const STATUS_TONES: Record<OfficerDataRequest["status"], StatusTone> = {
  pending: "warning",
  processing: "info",
  completed: "success",
  refused: "danger",
  on_hold: "danger",
};

const TYPE_LABELS: Record<OfficerDataRequest["type"], string> = {
  access: "اطّلاع",
  export: "تصدير",
  erasure: "حذف",
};

export default function ComplianceQueuePage() {
  const [requests, setRequests] = useState<OfficerDataRequest[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [reasons, setReasons] = useState<Record<string, string>>({});

  const load = useCallback(async () => {
    try {
      const { data } = await complianceQueue.list();
      setRequests(data);
      setError(null);
    } catch (cause) {
      // Never a blank screen: an empty list here reads as "nothing is
      // outstanding", which is the one thing an officer must not be told wrongly.
      setRequests([]);
      setError(userMessage(cause));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function act(uuid: string, run: () => Promise<unknown>) {
    setBusy(uuid);

    try {
      await run();
      await load();
      setError(null);
    } catch (cause) {
      setError(userMessage(cause));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">طلبات حقوق البيانات</h1>
        <p className="text-sm text-ink-muted">
          ما لم يُنفَّذ بعد، مرتَّباً بالأقرب إلى الموعد القانونيّ للردّ. التنفيذُ يُسجَّل باسمك.
        </p>
      </header>

      {error !== null && (
        <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
          {error}
        </Alert>
      )}

      {requests === null ? (
        <Card>
          <RowsSkeleton count={3} />
        </Card>
      ) : requests.length === 0 ? (
        <EmptyState
          title="لا طلبات معلّقة"
          description="كلُّ طلبات حقوق البيانات نُفِّذت أو أُغلقت."
        />
      ) : (
        <div className="space-y-4">
          {requests.map((request) => (
            <Card key={request.uuid}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                  <p className="text-sm font-medium text-ink">
                    {TYPE_LABELS[request.type]} —{" "}
                    <bdi>
                      {request.subject
                        ? `${request.subject.first_name} ${request.subject.last_name}`.trim()
                        : "حساب غير معروف"}
                    </bdi>
                  </p>
                  <p className="text-xs text-ink-muted">
                    الموعد الأقصى للردّ: {formatDate(request.due_at)}
                  </p>
                  {request.refusal_reason !== null && (
                    <p className="text-xs text-danger-ink">{request.refusal_reason}</p>
                  )}
                </div>

                <Badge tone={STATUS_TONES[request.status]}>{STATUS_LABELS[request.status]}</Badge>
              </div>

              {request.status === "pending" && (
                <div className="space-y-3 border-t border-line pt-3">
                  {/*
                    ⚠️ THE REFUSAL REASON IS A FIELD, NOT A CONFIRMATION DIALOG.
                    FR-026 wants who answered and why, and the server refuses an
                    empty reason with a 422 — so asking for it here is the screen
                    agreeing with the rule rather than discovering it.
                  */}
                  <TextareaField
                    id={`reason-${request.uuid}`}
                    label="سبب الرفض (يُسجَّل ويُبلَّغ لصاحب الطلب)"
                    rows={2}
                    value={reasons[request.uuid] ?? ""}
                    onChange={(value) => setReasons((all) => ({ ...all, [request.uuid]: value }))}
                  />

                  <div className="flex flex-wrap gap-2">
                    <Button
                      disabled={busy === request.uuid}
                      onClick={() => act(request.uuid, () => complianceQueue.execute(request.uuid))}
                    >
                      نفِّذ الطلب
                    </Button>

                    <Button
                      variant="danger"
                      disabled={busy === request.uuid || (reasons[request.uuid] ?? "").trim() === ""}
                      onClick={() =>
                        act(request.uuid, () =>
                          complianceQueue.refuse(request.uuid, reasons[request.uuid] ?? ""),
                        )
                      }
                    >
                      ارفض الطلب
                    </Button>
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
