"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  useIsSpeaking,
  useParticipantAttributes,
  useParticipants,
} from "@livekit/components-react";
import type { Participant } from "livekit-client";

import { Avatar } from "@/components/ui/Avatar";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { classSessions, type RoomParticipant } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";

/** How long a burst of arrivals is allowed to collapse into one roster fetch. */
const COALESCE_MS = 2000;

/**
 * The class roll during the lesson: a face, a name, the badges earned, and the
 * two buttons a teacher needs.
 *
 * ⚠️ THE PROVIDER KNOWS ONLY A UUID, AND THAT IS DELIBERATE (`FR-006`). The join
 * ticket sets the participant identity to the student's uuid and never their
 * name, because the identity is echoed by the provider to everyone in the room.
 * So the list used to read `participant.name || participant.identity` — and since
 * the name is never set, every row in every lesson was a raw uuid. The names come
 * from our own authenticated route instead, fetched once for the whole room.
 *
 * ⚠️ AND ONLY WHO IS CONNECTED IS DRAWN. The roster answers for everyone who
 * could be here; rendering it whole would publish the roll of everybody who did
 * NOT show up, on a screen every classmate can see.
 *
 * ⚠️ SOMEBODY THE ROSTER DOES NOT KNOW STILL GETS A ROW. A person who joined in
 * the seconds before the fetch, or whose profile was removed, is a participant we
 * can see and cannot name — so the row is drawn with «مشارك» rather than dropped,
 * because a face on the stage with no row beside it reads as a stranger nobody
 * can mute.
 */
export function ParticipantsPanel({
  sessionUuid,
  isHost,
}: {
  sessionUuid: string;
  isHost: boolean;
}) {
  const participants = useParticipants();
  const [roster, setRoster] = useState<Map<string, RoomParticipant>>(new Map());
  const [busy, setBusy] = useState("");
  /*
   * ⚠️ THE ERROR CARRIES THE KEY OF THE CONTROL THAT RAISED IT, and it used to be
   * a bare string rendered above the list. A failed «كتم» on row twelve then put
   * its reason several hundred pixels off the top of the screen, so the teacher
   * saw nothing happen and pressed again. Same key as `busy`, so the reason
   * appears where the finger already is.
   */
  const [error, setError] = useState<{ key: string; message: string } | null>(null);
  const [signals, setSignals] = useState<Map<string, { hand: number | null; confused: boolean }>>(
    new Map(),
  );
  // A counter, not a clock: the ORDER is the answer and nothing renders a time.
  const raiseCounter = useRef(0);

  /*
   * ⚠️ THE ORDER IS WHEN THIS SCREEN SAW THE HAND, NOT A NUMBER THE STUDENT SENT.
   *
   * A timestamp written into the attribute would be the obvious answer and is
   * the wrong one: attributes are written by the client that owns them, so the
   * student who wants to be first would simply say they were — and being asked
   * in turn is the entire point of the feature. What a browser cannot forge is
   * the moment THIS browser was told, and the teacher's screen is the only one
   * that needs a queue.
   *
   * The price is written down rather than hidden: after the teacher reloads, a
   * roomful of already-raised hands is seen at once and numbered arbitrarily.
   * Hands raised from then on queue correctly behind them.
   */
  const onSignals = useCallback((identity: string, hand: boolean, confused: boolean) => {
    setSignals((current) => {
      const previous = current.get(identity);
      const wasRaised = previous?.hand ?? null;

      if ((wasRaised !== null) === hand && (previous?.confused ?? false) === confused) {
        return current;
      }

      const next = new Map(current);
      next.set(identity, {
        hand: hand ? (wasRaised ?? ++raiseCounter.current) : null,
        confused,
      });

      return next;
    });
  }, []);

  /** Whether the roster has been asked for at least once (see the effect below). */
  const loaded = useRef(false);

  /*
   * Re-fetched when the count changes, never on every render and never per row:
   * somebody arriving is the only event that can add a name we do not have, and
   * the whole room is one query on the server.
   */
  const reload = useCallback(
    () =>
      classSessions
        .participants(sessionUuid)
        .then((response) => {
          // ⚠️ MARKED WHEN A LOAD LANDS, NOT WHEN THE EFFECT RUNS. React invokes
          // an effect twice on mount in development, so a flag set on entry made
          // the SECOND pass — the one that survives — take the coalescing delay,
          // and the panel showed nothing for two seconds on every entry.
          loaded.current = true;
          setRoster(new Map((response.data ?? []).map((person) => [person.uuid, person])));
        })
        // A roster that will not load changes nothing about what the room can do:
        // the lesson plays, the rows fall back to «مشارك», and the host buttons
        // still work — they target the identity, which comes from the provider.
        .catch(() => undefined),
    [sessionUuid],
  );

  /*
   * ⚠️ COALESCED, BECAUSE A ROOM FILLING UP IS N² FETCHES AND NOT N.
   *
   * The effect fires on every change of the count, and every client watches every
   * arrival — so client `i` of a thirty-seat room sees `31 − i` changes as the
   * rest arrive. Summed over the class that is 465 requests to an unthrottled
   * endpoint in about two minutes, each one a roster join plus a badge read, and
   * every room on the hour does it at once. The comment above was true of the
   * server («the whole room is one query») and quietly false of the client.
   *
   * The FIRST load is immediate and only the changes after it are coalesced: a
   * flat delay would leave the panel showing nothing for two seconds on entry,
   * which trades a server problem for the student's problem. Two seconds is short
   * enough that a late joiner is still «somebody» rather than a uuid.
   */
  useEffect(() => {
    const timer = setTimeout(() => void reload(), loaded.current ? COALESCE_MS : 0);

    return () => clearTimeout(timer);
  }, [reload, participants.length]);

  const act = async (action: "mute" | "remove", identity: string) => {
    setBusy(`${action}:${identity}`);
    setError(null);

    try {
      await classSessions.host(sessionUuid, action, identity);
      // Removing somebody drops them out of `participants`, which reloads the
      // roster anyway — but not before the teacher has looked, and the list of
      // who is out is the only place they can be let back in.
      await reload();
    } catch (err: unknown) {
      setError({ key: `${action}:${identity}`, message: userMessage(err) });
    } finally {
      setBusy("");
    }
  };

  const actOnRoom = async (action: "mute-all" | "remove-all" | "lower-hands") => {
    setBusy(action);
    setError(null);

    try {
      await classSessions.host(sessionUuid, action);
      await reload();
    } catch (err: unknown) {
      // A room action is pressed from the header, which is where this renders.
      setError({ key: action, message: userMessage(err) });
    } finally {
      setBusy("");
    }
  };

  /**
   * Let somebody back in.
   *
   * ⚠️ REMOVAL OUTLIVES THE DISCONNECT NOW, so it needs an undo. Before it did
   * not, and «إخراج» was worth exactly one refresh to the student; with the
   * refusal recorded, a press in error would otherwise keep a paying student out
   * of the lesson for the rest of the hour with nothing the teacher could do.
   */
  const readmit = async (uuid: string) => {
    setBusy(`readmit:${uuid}`);
    setError(null);

    try {
      await classSessions.host(sessionUuid, "readmit", uuid);
      await reload();
    } catch (err: unknown) {
      setError({ key: `readmit:${uuid}`, message: userMessage(err) });
    } finally {
      setBusy("");
    }
  };

  // Nobody connected still means something to draw for the host: whoever they
  // put out is only reachable from this panel.
  const removed = [...roster.values()].filter((person) => person.is_removed === true);

  /*
   * The raise counter only ever grows, so the numbers it hands out are not
   * «الأوّل، الثاني» — they are «الرابع، السابع» after a few rounds. Ranked here,
   * once for the whole panel, so the queue reads 1..n whatever the counter is at.
   */
  const queue = new Map(
    [...signals.entries()]
      .filter(([, signal]) => signal.hand !== null)
      .sort((a, b) => (a[1].hand ?? 0) - (b[1].hand ?? 0))
      .map(([identity], index): [string, number] => [identity, index + 1]),
  );

  const confusedCount = [...signals.values()].filter((signal) => signal.confused).length;

  if (participants.length === 0 && removed.length === 0) return null;

  return (
    <div className="mt-6 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 text-sm font-bold text-ink">
          <span>
            المشاركون (<bdi>{participants.length}</bdi>)
          </span>

          {/* The count, not the names: a teacher mid-explanation needs to know
              THAT four people are lost, and the names are one glance down. */}
          {confusedCount > 0 && (
            <Badge tone="warning">
              🤔 <bdi>{confusedCount}</bdi> لم يفهموا
            </Badge>
          )}
        </h3>

        {/*
          ⚠️ ONE REQUEST EACH, NOT A LOOP OVER THE ROWS. Twenty presses is twenty
          chances for one to fail in the middle and leave the room half muted
          with nothing saying which half — and the browser cannot tell a student
          from the recorder, which is a participant of its own.

          «إخراج الجميع» clears the room WITHOUT ending the lesson: the teacher
          keeps the room, the recording and the register, and the class can come
          back in. Ending is the button below the stage, and it is a different
          decision.
        */}
        {isHost && (
          <span className="flex flex-wrap gap-2">
            <Button
              variant="ghost"
              loading={busy === "lower-hands"}
              onClick={() => void actOnRoom("lower-hands")}
            >
              امسح الإشارات
            </Button>
            <Button
              variant="ghost"
              loading={busy === "mute-all"}
              onClick={() => void actOnRoom("mute-all")}
            >
              اكتم الجميع
            </Button>
            {/* ⚠️ ARMED, BECAUSE IT SITS EIGHT PIXELS FROM «اكتم الجميع» in a
                wrapping row on a teacher's phone. One of the two is recoverable
                in a tap and the other empties the lesson. */}
            <ConfirmButton
              confirmLabel="أكّد إخراج الجميع"
              loading={busy === "remove-all"}
              onConfirm={() => void actOnRoom("remove-all")}
            >
              أخرِج الجميع
            </ConfirmButton>
          </span>
        )}
      </div>

      {/* Only the ROOM-wide failures belong up here; a row's reason is drawn in
          its own row, beside the button that produced it. */}
      {error !== null && !error.key.includes(":") && (
        <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
          {error.message}
        </Alert>
      )}

      <ul className="space-y-2">
        {participants.map((participant) => (
          <ParticipantRow
            key={participant.identity}
            participant={participant}
            person={roster.get(participant.identity)}
            isHost={isHost}
            busy={busy}
            error={
              error !== null && error.key.endsWith(`:${participant.identity}`)
                ? error.message
                : null
            }
            onAct={act}
            queuePosition={queue.get(participant.identity) ?? null}
            onSignals={onSignals}
          />
        ))}
      </ul>

      {/*
        ⚠️ THE ONE PLACE THIS PANEL DRAWS SOMEBODY WHO IS NOT CONNECTED, and it
        is deliberate: a removed student is by definition out of the room, so
        without this list the teacher has nobody to press «اسمح بالعودة» on. It
        is the host's alone — the server does not even send the key to anyone
        else, because «فلان أُخرج» on every classmate's screen is a punishment
        nobody chose.
      */}
      {isHost && removed.length > 0 && (
        <div className="space-y-2 rounded-xl border border-line p-3">
          <p className="text-sm font-bold text-ink">أُخرجوا من هذه الحصة</p>

          <ul className="space-y-2">
            {removed.map((person) => (
              <li key={person.uuid} className="flex items-center justify-between gap-3">
                <span className="flex min-w-0 items-center gap-3">
                  <Avatar url={person.avatar_url} name={person.name} />
                  <span className="truncate text-sm text-ink-muted">{person.name}</span>
                </span>

                <Button
                  variant="ghost"
                  loading={busy === `readmit:${person.uuid}`}
                  onClick={() => void readmit(person.uuid)}
                >
                  اسمح بالعودة
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

/**
 * One row.
 *
 * Its own component so the attributes hook subscribes PER PARTICIPANT: a raised
 * hand must repaint that person's row and nothing else, and a single hook at the
 * top could only watch one of them.
 */
function ParticipantRow({
  participant,
  person,
  isHost,
  busy,
  error,
  onAct,
  queuePosition,
  onSignals,
}: {
  participant: Participant;
  person: RoomParticipant | undefined;
  isHost: boolean;
  busy: string;
  /** The reason THIS row's last action failed, if it did. */
  error: string | null;
  onAct: (action: "mute" | "remove", identity: string) => Promise<void>;
  queuePosition: number | null;
  onSignals: (identity: string, hand: boolean, confused: boolean) => void;
}) {
  const { attributes } = useParticipantAttributes({ participant });
  const isSpeaking = useIsSpeaking(participant);
  /*
   * ⚠️ READ AS BOOLEANS, NEVER PRINTED. Attributes are written by the client
   * that owns them — that is what makes a hand raise instantly without a round
   * trip — so a participant can put any sentence in there. Two keys, each
   * compared to one value, each rendered as an icon.
   */
  const handRaised = attributes?.hand === "1";
  const confused = attributes?.confused === "1";
  const name = person?.name ?? "مشارك";
  const identity = participant.identity;

  /*
   * The panel above keeps the queue, and this is where it learns. Reported on
   * every change rather than derived up there, because the attributes hook
   * subscribes per participant and only this component sees the moment a hand
   * goes up.
   */
  useEffect(() => {
    onSignals(identity, handRaised, confused);
  }, [onSignals, identity, handRaised, confused]);

  return (
    <li className="flex items-center justify-between gap-3 rounded-xl border border-line px-3 py-2">
      <span className="flex min-w-0 items-center gap-3">
        <Avatar url={person?.avatar_url ?? null} name={name} />

        <span className="flex min-w-0 flex-col gap-1">
          <span className="flex items-center gap-2">
            {/* Who is talking, without reading a single name. The library
                already tracks it; drawing it is what stops a class of twelve
                asking «مين اللي بيتكلّم؟» out loud over the person speaking. */}
            {isSpeaking && (
              <span
                // ⚠️ `secondary`: there is no `success` token, so `bg-success`
                // painted an 8px transparent circle. See BroadcastStage's ring.
                className="size-2 shrink-0 rounded-full bg-secondary"
                role="img"
                aria-label={`${name} يتحدّث الآن`}
              />
            )}

            <span className="truncate text-sm text-ink">{name}</span>

            {handRaised && (
              <span
                className="text-base"
                role="img"
                aria-label={
                  queuePosition === null
                    ? `${name} يرفع يده`
                    : `${name} يرفع يده — الدور ${queuePosition}`
                }
                title="يرفع يده"
              >
                ✋
                {queuePosition !== null && (
                  <bdi className="ms-1 text-xs font-bold text-ink-muted">{queuePosition}</bdi>
                )}
              </span>
            )}

            {confused && (
              <span
                className="text-base"
                role="img"
                aria-label={`${name} لم يفهم`}
                title="لم يفهم"
              >
                🤔
              </span>
            )}

            {person?.role === "host" && <Badge tone="info">المدرّس</Badge>}
            {person?.role === "staff" && <Badge tone="neutral">مساعد</Badge>}
          </span>

          {/* Nothing at all when there are none — never an empty row of chips,
              and never a dash. A teacher and a student on their first day both
              hold none, and absence is a state rather than a gap. */}
          {person !== undefined && person.badges.length > 0 && (
            <span className="flex flex-wrap gap-1">
              {person.badges.map((badge) => (
                <Badge key={badge.key} tone="success">
                  {badge.name_ar}
                </Badge>
              ))}
            </span>
          )}
        </span>
      </span>

      {isHost && !participant.isLocal && (
        <span className="flex shrink-0 gap-2">
          <Button
            variant="ghost"
            loading={busy === `mute:${participant.identity}`}
            onClick={() => void onAct("mute", participant.identity)}
          >
            كتم
          </Button>
          {/* Same eight pixels, same pair: «كتم» is undone in a tap and this
              puts a paying student out of the hour they booked. */}
          <ConfirmButton
            confirmLabel="أكّد الإخراج"
            loading={busy === `remove:${participant.identity}`}
            onConfirm={() => void onAct("remove", participant.identity)}
          >
            إخراج
          </ConfirmButton>
        </span>
      )}

      {/* Beside the button that failed, not at the top of a list the teacher is
          at the bottom of. `role="status"` so it is announced rather than found. */}
      {error !== null && (
        <span role="status" className="basis-full text-xs text-danger-ink">
          {error}
        </span>
      )}
    </li>
  );
}

