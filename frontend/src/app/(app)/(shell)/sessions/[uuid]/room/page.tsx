"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { BroadcastStage } from "@/components/sessions/BroadcastStage";
import { PresenceLoop } from "@/components/sessions/PresenceLoop";
import { RecordingNotice } from "@/components/compliance/RecordingNotice";
import { SessionChat } from "@/components/community/SessionChat";
import { Alert } from "@/components/ui/Alert";
import { UnlockNotice } from "@/components/sessions/UnlockNotice";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { Card } from "@/components/ui/Card";
import {
  classSessions,
  type ClassSession,
  type JoinTicket,
  type PresenceState,
} from "@/lib/class-sessions";
import { Button } from "@/components/ui/Button";
import { userMessage } from "@/lib/errors";
import { formatSessionTime } from "@/lib/session-format";
import { counted, NOUNS } from "@/lib/labels";
import { backoffDelay, isTransientFailure, MAX_AUTOMATIC_TRIES } from "@/lib/retry";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";
import { useAuth } from "@/lib/auth-context";
import { P, can } from "@/lib/permissions";

/** How often a student who is early asks whether the host has opened the room. */
const WAITING_POLL_MS = 20_000;
/** Consecutive failed reads before the waiting poll stops on its own. */
const WAITING_POLL_GIVE_UP_AFTER = 5;

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
  const zone = useViewerTimeZone();
  const { uuid } = use(params);
  const { user } = useAuth();
  /*
    ⚠️ «تفاصيل الحصة» LINKED INTO `/manage/sessions/{uuid}` FOR EVERY READER — a
    student or an assistant who met a closed room was sent to a screen the
    sidebar gate refuses them. Only `sessions.manage` opens that one; everybody
    else has the session's own page.
  */
  const detailsHref = can(user, P.sessionsManage) ? `/manage/sessions/${uuid}` : `/sessions/${uuid}`;

  const [ticket, setTicket] = useState<JoinTicket | null>(null);
  /*
    ⚠️ FETCHED ON THE HAPPY PATH TOO, AND IT USED TO BE ASKED FOR ONLY AFTER A
    REFUSAL. So the one screen a student and a teacher stare at for an hour said
    «غرفة الحصة» and nothing else: not the title, not the course, not the time,
    not whose lesson it is. A student with three teachers arriving from a
    notification had nothing on screen telling them they were in the right room —
    and the shell's own header prints «لوحة التحكم» above it, because no nav item
    is a prefix of this path.

    It costs one request and needs no new endpoint or field: `view` on the policy
    already admits every seat holder.
  */
  const [session, setSession] = useState<ClassSession | null>(null);
  const [presence, setPresence] = useState<PresenceState | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [ending, setEnding] = useState(false);
  // Ending is the only action here that succeeds by making the page empty, so
  // it needs a state of its own: without it a successful end is indentical to a
  // failed load — nothing on screen.
  const [ended, setEnded] = useState(false);
  // Not the same thing as `ended`: that one is «you just closed it», this one is
  // «it was already closed before you got here», and it survives a refresh.
  const [closed, setClosed] = useState(false);
  // Cancelled is a third answer: `CancelClassSession` never stamps
  // `room_closed_at`, so without this the host of a cancelled lesson read the
  // student sentence «تأكّد من حجز مقعدك». The status is not an entitlement
  // fact — every seat holder already sees it on their card.
  const [cancelledSession, setCancelledSession] = useState(false);
  // The window is open and the HOST has not opened the room yet. The join
  // refusal is uniform (FR-015) and reads «تأكّد من حجز مقعدك» — a dead end for
  // a student who is simply early. So we say so, watch the session for the
  // room to open, and knock once when it does.
  const [waiting, setWaiting] = useState(false);
  // Bumped to knock again — by the person (the retry button) or by the waiting
  // poll once the host has opened the room. The join effect re-runs on each change.
  const [attempt, setAttempt] = useState(0);
  // A transient failure (offline, 5xx, 429) is being retried on its own, so the
  // loading line says so instead of a bare «جارٍ التحضير» for half a minute.
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    let cancelled = false;
    let retryTimer: ReturnType<typeof setTimeout> | undefined;

    // Alongside the join, not inside its failure branch: the room needs a name
    // whether or not the door opens, and a refused student reading WHICH lesson
    // they were refused from is the difference between a rule and an outage.
    classSessions
      .show(uuid)
      .then((result) => {
        if (!cancelled) setSession(result);
      })
      .catch(() => undefined);

    /*
      ⛔ ONE POST PER KNOCK, AND A REFUSAL IS NEVER KNOCKED AGAIN ON ITS OWN.
      On 2026-09-26 one device sent 228 joins in two hours, every one a 403: the
      waiting poll re-posted the join every twenty seconds for the whole window.
      A 4xx is the server's answer and asking again cannot change it — so only a
      transient failure (`isTransientFailure`: offline, 5xx, 429) is retried
      here, with a capped backoff, and anything else stops and waits for a
      person to press «حاول مرة أخرى».
    */
    const knock = (failedTries: number) => {
      classSessions
        .join(uuid)
        .then((result) => {
          if (cancelled) return;
          setTicket(result);
          setError("");
          setRetrying(false);
          setLoading(false);
          // Inside now: stop watching for the host.
          setWaiting(false);
        })
        .catch((err: unknown) => {
          if (cancelled) return;

          const tries = failedTries + 1;
          if (isTransientFailure(err) && tries < MAX_AUTOMATIC_TRIES) {
            setRetrying(true);
            retryTimer = setTimeout(() => knock(tries), backoffDelay(tries));
            return;
          }

          setRetrying(false);
          setLoading(false);
          setError(userMessage(err));

          /*
            ⚠️ THE HOST WHO RELOADS AFTER ENDING THEIR OWN LESSON READS «تأكّد من
            حجز مقعدك». The join refusal is deliberately identical for every reason
            (FR-015), and the `ended` banner below only survives while the page
            does — so a refresh, a closed laptop, or coming back an hour later all
            answer a teacher with a sentence written for a student.

            Asking the session itself is what separates them, and it leaks nothing:
            `room_closed` is not an entitlement fact, and this endpoint is refused
            to anyone who could not read the session anyway — a stranger's fetch
            fails and they keep the uniform sentence, which is the requirement.
          */
          classSessions
            .show(uuid)
            .then((result) => {
              if (cancelled) return;
              if (result.status === "cancelled") {
                setCancelledSession(true);
                return;
              }
              if (result.room_closed) setClosed(true);
              // Self-terminating: once the window shuts `join_open` goes false
              // and the ordinary refusal takes over.
              setWaiting(result.join_open && !result.room_opened && !result.room_closed);
            })
            .catch(() => undefined);
        });
    };

    knock(0);

    return () => {
      cancelled = true;
      clearTimeout(retryTimer);
    };
  }, [uuid, attempt]);

  /*
    Waiting for the host. ⛔ IT POLLS THE SESSION (a GET), NEVER THE JOIN.

    It used to re-post the join every twenty seconds and read the refusal as
    «not yet» — which is also exactly what a student with no seat receives, so a
    refused tab knocked for the whole window (the 228 requests above). The session
    resource already says whether the room is open, so the page watches that and
    knocks ONCE when `room_opened` turns true; a refusal on that knock is final.

    Bounded three ways: the window shutting (`join_open` false), the room closing
    or the lesson being cancelled, and five consecutive failed reads. The
    `sessions` limiter allows 60/min per user; this is 3.
  */
  useEffect(() => {
    if (!waiting) return;

    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let misses = 0;

    const look = () => {
      timer = setTimeout(() => {
        classSessions
          .show(uuid)
          .then((result) => {
            if (cancelled) return;
            misses = 0;
            setSession(result);

            if (result.status === "cancelled") {
              setCancelledSession(true);
              setWaiting(false);
              return;
            }
            if (result.room_closed) {
              setClosed(true);
              setWaiting(false);
              return;
            }
            if (!result.join_open) {
              setWaiting(false);
              return;
            }
            if (result.room_opened) {
              setWaiting(false);
              setError("");
              setLoading(true);
              setAttempt((n) => n + 1);
              return;
            }

            look();
          })
          .catch(() => {
            if (cancelled) return;
            misses += 1;
            if (misses >= WAITING_POLL_GIVE_UP_AFTER) {
              setWaiting(false);
              return;
            }
            look();
          });
      }, WAITING_POLL_MS);
    };

    look();

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [waiting, uuid]);

  // The person asked: one knock, and the automatic budget starts again from zero.
  const retry = () => {
    setError("");
    setLoading(true);
    setAttempt((n) => n + 1);
  };

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
      <div>
        <h2 className="text-xl font-bold text-ink">{session?.title ?? "غرفة الحصة"}</h2>

        {session !== null && (
          <p className="mt-1 text-sm text-ink-muted">
            {formatSessionTime(session.starts_at, zone)} ·{" "}
            {counted(session.duration_minutes, NOUNS.minutes)}
            {session.course !== undefined && <> · {session.course.title}</>}
          </p>
        )}
      </div>

      {loading && (
        <p role="status" className="text-sm text-ink-muted">
          {retrying ? "تعذّر الاتصال بالغرفة — نعيد المحاولة تلقائياً…" : "جارٍ التحضير…"}
        </p>
      )}

      {error !== "" && cancelledSession && (
        <Alert tone="info" title="هذه الحصة ملغاة">
          أُلغيت هذه الحصة، فلا غرفة لها.
        </Alert>
      )}

      {error !== "" && closed && !cancelledSession && (
        <Alert tone="info" title="انتهت هذه الحصة">
          أُغلقت غرفة البثّ، فلا دخول إليها. إن كان لها تسجيل فسيظهر درساً في الكورس.
          <div className="mt-3">
            <Link
              href={detailsHref}
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              تفاصيل الحصة
            </Link>
          </div>
        </Alert>
      )}

      {error !== "" && waiting && (
        <Alert tone="info" title="لم يفتح المدرّس الغرفة بعد">
          ستدخل تلقائياً حين يفتحها — لا حاجة لإعادة تحميل الصفحة.
        </Alert>
      )}

      {error !== "" && !closed && !waiting && !cancelledSession && (
        <>
          <Alert tone="danger" title="تعذّر الدخول">
            {error}
            {/* Nothing knocks again on its own after a refusal, so the one way
                back is a button — pressed by a person, one request per press. */}
            <div className="mt-3">
              <Button variant="secondary" size="sm" onClick={retry}>
                حاول مرة أخرى
              </Button>
            </div>
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
              href={detailsHref}
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

            {/* The host's beat keeps a register row too — it is how delivery is
                judged — but «مدة حضورك» is a student's line: the teacher is
                teaching the lesson, not attending it. */}
            {presence !== null && ticket.role !== "host" && (
              <p className="text-sm text-ink-muted">
                مدة حضورك حتى الآن <bdi>{Math.floor(presence.stay_seconds / 60)}</bdi> دقيقة.
              </p>
            )}

            {ticket.role === "host" && (
              <div className="mt-4">
                {/* One tap ended the broadcast for everyone, with nothing to
                    take it back — and the room cannot be reopened afterwards. */}
                <ConfirmButton
                  onConfirm={() => void end()}
                  loading={ending}
                  confirmLabel="أكّد إنهاء الحصة"
                >
                  إنهاء الحصة
                </ConfirmButton>
              </div>
            )}
          </Card>
        </>
      )}

      {/*
        Spec 010 · US3 — the room's own chat, under the stage.

        ⚠️ RENDERED OUTSIDE THE `ticket` BRANCH ON PURPOSE. A student whose ticket
        was refused for a reason that has nothing to do with entitlement — the
        clock, a provider hiccup — can still read and ask; and `SessionChat`
        renders NOTHING for anyone the server refuses, so there is no branch to
        keep in step here.
      */}
      <SessionChat kind="session" uuid={uuid} />
    </div>
  );
}
