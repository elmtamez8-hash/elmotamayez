"use client";

import { useCallback, useEffect, useState } from "react";

import { SessionsIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import {
  counted,
  NOUNS,
  privateSessionStatusLabel,
  privateSessionStatusTone,
} from "@/lib/labels";
import { privateSessions, type PrivateSessionRequest } from "@/lib/private-sessions";
import { formatSessionTime } from "@/lib/session-format";

/**
 * «حصصي الخاصة» — the student's own private-session asks (spec 023 · US3).
 *
 * ⚠️ THIS SCREEN IS THE OTHER HALF OF THE TEACHER'S QUEUE, AND IT DID NOT EXIST.
 * `GET /private-session-requests` and the withdraw route shipped with the queue,
 * and nothing in the frontend called either: a student who asked for an hour
 * learned the answer only from a notification, could not see a refusal's reason
 * twice, and could not take back an ask they no longer wanted — so it sat in the
 * teacher's queue until it expired.
 *
 * ⚠️ THE REFUSAL'S REASON IS SHOWN, because that is the whole of FR-018: a «no»
 * with no words reads as a fault and is asked for again, which is the teacher's
 * queue twice.
 *
 * ⚠️ TIMES ARE IN THE DECLARED SESSION ZONE the row carries, never the browser's
 * — the same hour the lesson will show once it is accepted.
 *
 * Withdrawing is confirmed in a `Modal`, not a two-press `ConfirmButton`: nothing
 * else is happening on this screen, which is the line `Modal` draws.
 */
export default function MyPrivateSessionsPage() {
  const [requests, setRequests] = useState<PrivateSessionRequest[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [withdrawing, setWithdrawing] = useState<PrivateSessionRequest | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoadError(null);

    privateSessions
      .mine()
      .then((res) => setRequests(res.data ?? []))
      .catch((err: unknown) => setLoadError(userMessage(err)));
  }, []);

  useEffect(load, [load]);

  const withdraw = () => {
    if (withdrawing === null) return;

    setBusy(true);
    setActionError(null);

    privateSessions
      .withdraw(withdrawing.uuid)
      .then(() => {
        setWithdrawing(null);
        load();
      })
      // The 409 says the teacher has already answered — a sentence the student
      // can act on, never «تعذّر».
      .catch((err: unknown) => {
        setWithdrawing(null);
        setActionError(userMessage(err));
        load();
      })
      .finally(() => setBusy(false));
  };

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={SessionsIcon}
        title="حصصي الخاصة"
        description="طلبات الحصص الخاصة التي أرسلتها، وردّ المدرّس على كلّ منها. لا يُخصم من رصيدك شيء قبل القبول."
      />

      {actionError !== null && (
        <Alert tone="danger" title="لم يُسحب الطلب">
          {actionError}
        </Alert>
      )}

      {loadError !== null && requests === null && <ErrorState onRetry={load} />}

      {loadError === null && requests === null && <RowsSkeleton count={3} />}

      {requests !== null && requests.length === 0 && (
        <EmptyState
          title="لم تطلب حصة خاصة بعد"
          description="اطلب حصة خاصة من صفحة الكورس الذي اشتركت فيه، على موعد من مواعيد المدرّس المعلَنة، وستجد طلبك هنا."
          action={<Button href="/enrollments">كورساتي</Button>}
        />
      )}

      {requests?.map((request) => (
        <Card key={request.uuid}>
          <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-bold text-ink">
                {request.course?.title ?? "حصة خاصة"}
              </h3>
              <Badge tone={privateSessionStatusTone(request.status)}>
                {privateSessionStatusLabel(request.status)}
              </Badge>
            </div>

            <p className="text-sm text-ink">
              {formatSessionTime(request.starts_at, request.timezone)} ·{" "}
              {counted(request.duration_minutes, NOUNS.minutes)}
            </p>

            {request.status === "pending" && (
              <p className="text-xs text-ink-muted">
                إن لم يردّ المدرّس، ينتهي الطلب {formatSessionTime(request.expires_at, request.timezone)}.
              </p>
            )}

            {request.status === "rejected" && request.decision_reason && (
              <div className="rounded-xl border border-line bg-surface p-3 text-sm text-ink">
                <p className="mb-1 text-xs font-bold text-ink-muted">سبب الرفض من المدرّس</p>
                <p className="whitespace-pre-line">{request.decision_reason}</p>
              </div>
            )}

            {request.status === "accepted" && request.class_session_uuid && (
              <div>
                <Button href={`/sessions/${request.class_session_uuid}`} variant="secondary" size="sm">
                  صفحة الحصة
                </Button>
              </div>
            )}

            {request.status === "pending" && (
              <div>
                <Button variant="ghost" size="sm" onClick={() => setWithdrawing(request)}>
                  سحب الطلب
                </Button>
              </div>
            )}
          </div>
        </Card>
      ))}

      <Modal
        open={withdrawing !== null}
        title="سحب طلب الحصة الخاصة"
        message={
          withdrawing === null
            ? undefined
            : `سيُسحب طلبك لحصة ${formatSessionTime(withdrawing.starts_at, withdrawing.timezone)} ولن يصل إلى المدرّس. يمكنك طلب موعد آخر بعدها.`
        }
        confirmLabel="اسحب الطلب"
        tone="danger"
        busy={busy}
        onConfirm={withdraw}
        onCancel={() => setWithdrawing(null)}
      />
    </div>
  );
}
