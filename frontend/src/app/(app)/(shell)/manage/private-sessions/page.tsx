"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { userMessage } from "@/lib/errors";
import {
  privateSessions,
  type PrivateSessionRequest,
} from "@/lib/private-sessions";

/**
 * The teacher's queue of private-session asks (FR-018).
 *
 * ⚠️ A REFUSAL CARRIES THE TEACHER'S OWN WORDS, AND THE API REFUSES WITHOUT
 * THEM. The student reads it: a refusal with no reason is indistinguishable from
 * a request still waiting, so it is asked for again — which is this same queue,
 * twice.
 *
 * ⚠️ AND THE DEADLINE IS ON EVERY ROW. A request expires itself; a queue that did
 * not say when would let a teacher plan to answer tomorrow a request that ends
 * tonight, and the student would be told «انتهت المهلة» about a lesson their
 * teacher meant to give them.
 */
export default function PrivateSessionQueuePage() {
  const [requests, setRequests] = useState<PrivateSessionRequest[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState<string | null>(null);

  const load = useCallback(() => {
    privateSessions
      .queue()
      .then((res) => setRequests(res.data))
      .catch((err: unknown) => setError(userMessage(err)));
  }, []);

  useEffect(load, [load]);

  const decide = (uuid: string, accept: boolean) => {
    setPending(uuid);
    setError(null);

    const call = accept
      ? privateSessions.accept(uuid)
      : privateSessions.reject(uuid, reason);

    call
      .then(() => {
        setRejecting(null);
        setReason("");
        load();
      })
      // The server's sentence names what happened — the balance ran out, the
      // hour is taken, somebody already answered. Never «تعذّر».
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
        <h1 className="text-2xl font-black text-ink">طلبات الحصص الخاصة</h1>
        <p className="text-sm text-ink-muted">
          طلبات من طلابك على مواعيدك المعلَنة. لا تُنشأ حصة ولا يُخصم رصيد قبل
          موافقتك.
        </p>
      </div>

      {error && <Alert tone="danger" title="لم يكتمل الإجراء">{error}</Alert>}

      {requests === null && <p className="text-sm text-ink-muted">جارٍ التحميل…</p>}

      {requests !== null && requests.length === 0 && (
        <EmptyState
          title="لا طلبات تنتظر"
          description="حين يطلب أحد طلابك حصة خاصة من مواعيدك المعلَنة سيظهر طلبه هنا."
        />
      )}

      {requests?.map((request) => (
        <Card key={request.uuid}>
          <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="text-sm font-bold text-ink">
                {request.student?.name ?? "طالب"} — {request.course?.title ?? "كورس"}
              </h2>
              <span className="text-xs text-ink-muted">
                تنتهي المهلة {when(request.expires_at)}
              </span>
            </div>

            <p className="text-sm text-ink">
              {when(request.starts_at)} · {request.duration_minutes} دقيقة
            </p>

            {rejecting === request.uuid ? (
              <div className="flex flex-col gap-3">
                <TextareaField
                  id={`reason-${request.uuid}`}
                  label="سبب الرفض"
                  hint="يقرؤه الطالب، فاكتب ما يساعده على اختيار موعد آخر."
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
                  قبول
                </Button>
                <Button variant="ghost" onClick={() => setRejecting(request.uuid)}>
                  رفض
                </Button>
              </div>
            )}
          </div>
        </Card>
      ))}
    </div>
  );
}
