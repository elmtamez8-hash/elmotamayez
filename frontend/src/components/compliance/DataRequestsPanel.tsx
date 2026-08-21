"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { dataRights, type DataRequestRecord } from "@/lib/compliance";
import { userMessage } from "@/lib/errors";
import { formatDate, type StatusTone } from "@/lib/labels";

/**
 * A person's own data-rights requests (FR-015 · FR-018 · FR-019).
 *
 * ⚠️ THE STATE IS READ FROM THE SERVER, NOT DERIVED HERE. `is_downloadable` is the
 * file AND its expiry together, computed where both are known — a client that
 * decided it from `status === "completed"` would offer a download for an archive
 * last night's cleaner removed, and the button would answer 404 with no
 * explanation.
 *
 * ⚠️ AND ERASURE IS DELIBERATELY NOT OFFERED YET. The Action behind it lands with
 * `US4`; a button that opened a request nothing executes would leave a person
 * believing their data was being deleted while the row sat `pending` for ever.
 * Offering only what runs is the honest half of a rights screen.
 */
const STATUS_LABELS: Record<DataRequestRecord["status"], string> = {
  pending: "في الانتظار",
  processing: "قيد التنفيذ",
  completed: "جاهز",
  refused: "مرفوض",
  on_hold: "موقوف",
};

const STATUS_TONES: Record<DataRequestRecord["status"], StatusTone> = {
  pending: "neutral",
  processing: "info",
  completed: "success",
  refused: "danger",
  on_hold: "danger",
};

const TYPE_LABELS: Record<DataRequestRecord["type"], string> = {
  access: "اطّلاع",
  export: "نسخة من بياناتي",
  erasure: "حذف بياناتي",
};

export function DataRequestsPanel() {
  const [requests, setRequests] = useState<DataRequestRecord[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const { data } = await dataRights.list();
      setRequests(data);
      setError(null);
    } catch (cause) {
      // ⚠️ NEVER A RAW ERROR, AND NEVER A BLANK SCREEN EITHER. A swallowed
      // failure renders an empty list that reads as "you have never asked".
      setRequests([]);
      setError(userMessage(cause));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function request() {
    setBusy(true);

    try {
      await dataRights.create({ type: "export" });
      await load();
      setError(null);
    } catch (cause) {
      setError(userMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  async function download(uuid: string) {
    try {
      await dataRights.download(uuid);
    } catch (cause) {
      setError(userMessage(cause));
    }
  }

  const open = (requests ?? []).some(
    (request) => request.status === "pending" || request.status === "processing",
  );

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-ink">طلب نسخة من بياناتي</h2>
          <p className="text-sm text-ink-muted">
            نُجهّز ملفاً يضمّ كلّ ما نحتفظ به عنك، ويبقى رابطُ تنزيله متاحاً مدّةً قصيرة.
          </p>
        </div>

        {/*
          ⚠️ DISABLED WHILE ONE IS OPEN, and the server refuses a second anyway:
          `open_key` is a unique column, so two taps in one second produce one
          request. This is the explanation, not the guard — a client-side check
          alone is not one.
        */}
        <Button onClick={request} disabled={busy || open}>
          {open ? "لديك طلبٌ قيد التنفيذ" : "اطلب نسخة"}
        </Button>
      </div>

      {error !== null && (
        <Alert tone="danger" title="تعذّر تنفيذ الطلب">
          {error}
        </Alert>
      )}

      {requests === null ? (
        <RowsSkeleton count={2} />
      ) : requests.length === 0 ? (
        <EmptyState title="لا طلبات بعد" description="لم تطلب نسخةً من بياناتك حتى الآن." />
      ) : (
        <ul className="space-y-3">
          {requests.map((request) => (
            <li
              key={request.uuid}
              className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3"
            >
              <div className="space-y-1">
                <p className="text-sm font-medium text-ink">{TYPE_LABELS[request.type]}</p>
                <p className="text-xs text-ink-muted">
                  {request.completed_at !== null
                    ? `جاهز في ${formatDate(request.completed_at)}`
                    : request.due_at !== null
                      ? `الموعد الأقصى للردّ: ${formatDate(request.due_at)}`
                      : null}
                </p>
                {request.refusal_reason !== null && (
                  <p className="text-xs text-danger-ink">{request.refusal_reason}</p>
                )}
              </div>

              <div className="flex items-center gap-2">
                <Badge tone={STATUS_TONES[request.status]}>{STATUS_LABELS[request.status]}</Badge>

                {request.is_downloadable && (
                  <Button variant="secondary" onClick={() => download(request.uuid)}>
                    نزِّل الملفّ
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
