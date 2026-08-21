"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { BroadcastStage } from "@/components/sessions/BroadcastStage";
import { PresenceLoop } from "@/components/sessions/PresenceLoop";
import { RecordingNotice } from "@/components/compliance/RecordingNotice";
import { Alert } from "@/components/ui/Alert";
import { UnlockNotice } from "@/components/sessions/UnlockNotice";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { classSessions, type JoinTicket, type PresenceState } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";

/**
 * The room.
 *
 * The ticket is asked for on mount and never stored anywhere durable: it is
 * short-lived, and eligibility is re-evaluated on the server every time it is
 * issued. A copied ticket is worth nothing once the room closes.
 *
 * Everything the page shows about attendance comes back from the heartbeat.
 * Nothing is counted here.
 */
export default function SessionRoomPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);

  const [ticket, setTicket] = useState<JoinTicket | null>(null);
  const [presence, setPresence] = useState<PresenceState | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [ending, setEnding] = useState(false);
  // Ending is the only action here that succeeds by making the page empty, so
  // it needs a state of its own: without it a successful end is indentical to a
  // failed load — nothing on screen.
  const [ended, setEnded] = useState(false);

  useEffect(() => {
    let cancelled = false;

    classSessions
      .join(uuid)
      .then((result) => {
        if (!cancelled) setTicket(result);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(userMessage(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [uuid]);

  const onPresence = useCallback((state: PresenceState) => setPresence(state), []);

  const end = async () => {
    setEnding(true);
    // Cleared before the attempt, not after it: a retry that succeeds used to
    // leave the previous failure's banner on screen next to an empty room,
    // which reads as "it failed again" when it did not.
    setError("");

    try {
      await classSessions.host(uuid, "end");
      setTicket(null);
      setEnded(true);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setEnding(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <h2 className="text-xl font-bold text-ink">غرفة الحصة</h2>

      {loading && <p className="text-sm text-ink-muted">جارٍ التحضير…</p>}

      {error !== "" && (
        <>
          <Alert tone="danger" title="تعذّر الدخول">
            {error}
          </Alert>
          {/*
            ⚠️ ONLY AFTER A REFUSAL, and only then. The join answer says the door
            is shut; this says WHY in terms the student can act on (FR-038) —
            «تعذّر الدخول» alone is indistinguishable from an outage, and a
            student who cannot tell a rule from a bug writes to their teacher.
            Asked here rather than on load, so an ordinary entry costs nothing.
          */}
          <UnlockNotice sessionUuid={uuid} />
        </>
      )}

      {/*
        Says the door is shut and points away from this page. Reloading the room
        after ending it hits the join refusal, which is deliberately identical
        for every reason (FR-015) — so it tells a teacher who just closed the
        room to go book a seat. The fix is not to weaken that answer; it is to
        give the host somewhere else to be.
      */}
      {ended && (
        <Alert tone="success" title="أُنهيت الحصة">
          أُغلقت الغرفة ولا يمكن الدخول إليها مجدداً. كشف الحضور يُقفَل في موعد انتهاء الحصة.
          <div className="mt-3">
            <Link
              href={`/manage/sessions/${uuid}`}
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              العودة إلى تفاصيل الحصة
            </Link>
          </div>
        </Alert>
      )}

      {ticket !== null && (
        <>
          {/*
            ⚠️ ABOVE THE STAGE, AND BEFORE ANY RECORDING STARTS (FR-013). A notice
            that appears when recording BEGINS is not a notice — the participant is
            already in the file by the time they read it. It is drawn as soon as
            the room opens, because "this room is recorded" is a property of the
            session, not of the current second.
          */}
          <RecordingNotice />

          <Card padding="sm">
            <BroadcastStage ticket={ticket} sessionUuid={uuid} />
          </Card>

          <Card>
            <PresenceLoop
              sessionUuid={uuid}
              intervalSeconds={ticket.presence_interval_seconds}
              onUpdate={onPresence}
            />

            {presence !== null && (
              <p className="text-sm text-ink-muted">
                مدة حضورك حتى الآن <bdi>{Math.floor(presence.stay_seconds / 60)}</bdi> دقيقة.
              </p>
            )}

            {ticket.role === "host" && (
              <div className="mt-4">
                <Button onClick={end} loading={ending} variant="danger">
                  إنهاء الحصة
                </Button>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
