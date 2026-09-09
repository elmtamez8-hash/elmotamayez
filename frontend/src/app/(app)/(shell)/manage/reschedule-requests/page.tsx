"use client";

import { useCallback, useEffect, useState } from "react";

import { ChevronEndIcon, ScheduleIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { userMessage } from "@/lib/errors";
import { rescheduleRequests, type RescheduleRequest } from "@/lib/reschedule-requests";

/**
 * The teacher's queue of postponement asks (spec 049).
 *
 * ⚠️ AN APPROVAL CAN FAIL, AND THE SENTENCE IS THE WHOLE POINT. The move goes
 * through the same action that schedules a lesson, so a proposed hour that lands
 * on another group is refused with «الموعد يتعارض…» — and the request stays in
 * this queue for the teacher to answer differently. Replacing that with «تعذّر
 * تنفيذ الإجراء» would leave them pressing the same button for ever.
 *
 * ⚠️ AND A REFUSAL CARRIES THE TEACHER'S OWN WORDS, WHICH THE API DEMANDS. The
 * student reads it: a refusal with no reason is indistinguishable from a request
 * still waiting, so it is asked again — which is this same queue, twice.
 */
export default function RescheduleQueuePage() {
  const [requests, setRequests] = useState<RescheduleRequest[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState<string | null>(null);

  const load = useCallback(() => {
    rescheduleRequests
      .queue()
      .then((res) => setRequests(res.data))
      .catch((err: unknown) => setError(userMessage(err)));
  }, []);

  useEffect(load, [load]);

  const decide = (uuid: string, approve: boolean) => {
    setPending(uuid);
    setError(null);

    (approve ? rescheduleRequests.approve(uuid) : rescheduleRequests.reject(uuid, reason))
      .then(() => {
        setRejecting(null);
        setReason("");
        load();
      })
      .catch((err: unknown) => setError(userMessage(err)))
      .finally(() => setPending(null));
  };

  const when = (iso: string) =>
    new Date(iso).toLocaleString("ar-QA", {
      weekday: "long",
      day: "numeric",
      month: "long",
      hour: "2-digit",
      minute: "2-digit",
    });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-2xl font-black text-ink">
          <ScheduleIcon className="h-6 w-6 text-primary-ink" />
          طلبات تأجيل الحصص
        </h1>
        <p className="text-sm text-ink-muted">
          تأجيل حصة واحدة بعينها. الحصة التالية تبقى في موعدها المعتاد، ولا شيء
          يتحرك قبل موافقتك.
        </p>
      </div>

      {error !== null && (
        <Alert tone="danger" title="لم يكتمل الإجراء">
          {error}
        </Alert>
      )}

      {requests === null && <p className="text-sm text-ink-muted">جارٍ التحميل…</p>}

      {requests !== null && requests.length === 0 && (
        <EmptyState
          title="لا طلبات تنتظر"
          description="حين يطلب أحد طلابك تأجيل حصة من جدوله سيظهر طلبه هنا."
        />
      )}

      {requests?.map((request, index) => (
        <div
          key={request.uuid}
          className="animate-float-in"
          /* Capped so a long queue does not make the last card arrive a second
             after the first; the reduced-motion block zeroes delay and duration. */
          style={{ animationDelay: `${Math.min(index, 6) * 45}ms` }}
        >
          <Card>
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-bold text-ink">
                  {request.student?.name ?? "طالب"} — {request.session?.title ?? "حصة"}
                </h2>
              </div>

              {/* ⚠️ BOTH TIMES, SIDE BY SIDE. «إلى الأحد ٦م» alone makes a
                  teacher open their calendar to find out what is being given
                  up — which is the one fact the decision turns on. */}
              <p className="flex flex-wrap items-center gap-2 text-sm text-ink">
                <span className="text-ink-muted line-through">{when(request.from_starts_at)}</span>
                <ChevronEndIcon className="h-4 w-4 text-ink-muted" />
                <span className="font-bold">{when(request.to_starts_at)}</span>
              </p>

              {request.student_reason !== null && (
                <p className="rounded-xl bg-primary-soft p-3 text-sm text-ink">
                  {request.student_reason}
                </p>
              )}

              {rejecting === request.uuid ? (
                <div className="flex flex-col gap-3">
                  <TextareaField
                    id={`reason-${request.uuid}`}
                    label="سبب الرفض"
                    hint="يقرؤه الطالب، فاكتب ما يساعده على اقتراح موعد آخر."
                    required
                    value={reason}
                    onChange={setReason}
                    rows={3}
                  />
                  <div className="flex flex-wrap gap-2">
                    <Button
                      variant="danger"
                      disabled={reason.trim() === "" || pending === request.uuid}
                      onClick={() => decide(request.uuid, false)}
                    >
                      أرسل الرفض
                    </Button>
                    <Button variant="ghost" onClick={() => setRejecting(null)}>
                      تراجع
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Button
                    disabled={pending === request.uuid}
                    onClick={() => decide(request.uuid, true)}
                  >
                    وافِق وانقل الحصة
                  </Button>
                  <Button variant="ghost" onClick={() => setRejecting(request.uuid)}>
                    رفض
                  </Button>
                </div>
              )}
            </div>
          </Card>
        </div>
      ))}
    </div>
  );
}
