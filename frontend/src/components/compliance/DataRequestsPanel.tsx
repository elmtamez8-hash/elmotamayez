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
 * ⚠️ AND AN ERASURE SAYS «طُلِب» RATHER THAN «جارٍ». Asking does not start one:
 * FR-019 gives the right to ASK with an announced execution period, and the request
 * waits for a person in the compliance queue to run it. A screen that implied the
 * deletion had begun would be describing something that has not happened — and the
 * person would stop looking for the answer that is still coming.
 */
const STATUS_LABELS: Record<DataRequestRecord["status"], string> = {
  pending: "في الانتظار",
  processing: "قيد التنفيذ",
  completed: "تمّ",
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

  async function request(type: DataRequestRecord["type"]) {
    setBusy(true);

    try {
      await dataRights.create({ type });
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

  const isOpen = (type: DataRequestRecord["type"]) =>
    (requests ?? []).some(
      (request) =>
        request.type === type &&
        (request.status === "pending" || request.status === "processing" || request.status === "on_hold"),
    );

  const openExport = isOpen("export");
  const openErasure = isOpen("erasure");

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-ink">بياناتي: نسخةٌ أو حذف</h2>
          <p className="text-sm text-ink-muted">
            نُجهّز ملفاً يضمّ كلّ ما نحتفظ به عنك، ويبقى رابطُ تنزيله متاحاً مدّةً قصيرة.
            وطلبُ الحذف يُراجَع قبل تنفيذه، وبعضُ السجلّات — كالشهادات والقيود الماليّة —
            يبقى بحكم القانون بعد فصلِه عن هويّتك.
          </p>
        </div>

        {/*
          ⚠️ DISABLED WHILE ONE IS OPEN, and the server refuses a second anyway:
          `open_key` is a unique column, so two taps in one second produce one
          request. This is the explanation, not the guard — a client-side check
          alone is not one.
        */}
        <div className="flex flex-wrap items-center gap-2">
          <Button onClick={() => request("export")} disabled={busy || openExport}>
            {openExport ? "طلبُ النسخة قيد التنفيذ" : "اطلب نسخة"}
          </Button>

          {/*
            ⚠️ A SEPARATE OPEN-STATE PER TYPE, because the server locks per type:
            `open_key` is `{subject}:{type}`, so an export in flight does not stop
            somebody asking to be erased. One shared flag here would disable the
            erasure button for as long as an unrelated export took — the second
            right unreachable while the first was pending.
          */}
          <Button variant="danger" onClick={() => request("erasure")} disabled={busy || openErasure}>
            {openErasure ? "طلبُ الحذف قيد المراجعة" : "اطلب حذف بياناتي"}
          </Button>
        </div>
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
