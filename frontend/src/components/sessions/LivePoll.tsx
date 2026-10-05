"use client";

import { useEffect, useMemo, useState } from "react";
import { useLocalParticipant, useParticipantAttributes, useRoomContext } from "@livekit/components-react";
import { RoomEvent, type Participant } from "livekit-client";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { classSessions } from "@/lib/class-sessions";
import { arabicNumber } from "@/lib/numerals";
import {
  decodePoll,
  encodePoll,
  MAX_OPTION,
  MAX_OPTIONS,
  MAX_QUESTION,
  MIN_OPTIONS,
  newPollId,
  percent,
  POLL_ATTRIBUTE,
  POLL_PRESETS,
  POLL_TOPIC,
  readVote,
  tally,
  voteValue,
  type Poll,
} from "@/lib/live-poll";

/** «تصويت سريع» — the teacher's panel, or the student's card. See `lib/live-poll.ts`. */
export function LivePoll({ sessionUuid, isHost }: { sessionUuid: string; isHost: boolean }) {
  return isHost ? <HostPoll sessionUuid={sessionUuid} /> : <StudentPoll />;
}

const storageKey = (sessionUuid: string) => `live-poll:${sessionUuid}`;

/** The poll outlives a reload of the teacher's tab (the votes are the students'). */
function storedPoll(sessionUuid: string): Poll | null {
  try {
    const raw = sessionStorage.getItem(storageKey(sessionUuid));
    return raw ? decodePoll(new TextEncoder().encode(raw)) : null;
  } catch {
    return null;
  }
}

function HostPoll({ sessionUuid }: { sessionUuid: string }) {
  const room = useRoomContext();
  const [poll, setPoll] = useState<Poll | null>(() => storedPoll(sessionUuid));
  const [drafting, setDrafting] = useState(false);
  const [question, setQuestion] = useState("");
  const [options, setOptions] = useState<string[]>(["", ""]);
  const [votes, setVotes] = useState<[string, string | undefined][]>([]);
  const [names, setNames] = useState<Map<string, string>>(new Map());
  const [error, setError] = useState("");

  const send = (next: Poll, to?: string[]) =>
    room.localParticipant.publishData(encodePoll(next), { reliable: true, topic: POLL_TOPIC, destinationIdentities: to });

  // Every seat's vote, re-read whenever one changes or somebody arrives.
  useEffect(() => {
    const read = () =>
      setVotes([...room.remoteParticipants.values()].map((p): [string, string | undefined] => [p.identity, p.attributes[POLL_ATTRIBUTE]]));
    read();
    // ⚠️ Connected too: the seats already in the room arrive with the join answer
    // and raise no ParticipantConnected, so a reloaded teacher would read zero.
    room
      .on(RoomEvent.Connected, read)
      .on(RoomEvent.ParticipantAttributesChanged, read)
      .on(RoomEvent.ParticipantConnected, read)
      .on(RoomEvent.ParticipantDisconnected, read);
    return () => {
      room
        .off(RoomEvent.Connected, read)
        .off(RoomEvent.ParticipantAttributesChanged, read)
        .off(RoomEvent.ParticipantConnected, read)
        .off(RoomEvent.ParticipantDisconnected, read);
    };
  }, [room]);

  // A second host in the room (an assistant hosting) is heard, not overridden:
  // their poll becomes this screen's poll, so the class has one.
  useEffect(() => {
    const receive = (payload: Uint8Array, _from?: Participant, _kind?: unknown, topic?: string) => {
      if (topic !== POLL_TOPIC) return;
      const next = decodePoll(payload);
      if (next) remember(next);
    };
    room.on(RoomEvent.DataReceived, receive);
    return () => {
      room.off(RoomEvent.DataReceived, receive);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `remember` writes state and storage only
  }, [room]);

  // A data message is not replayed: whoever arrives (or reloads) is sent the poll.
  useEffect(() => {
    if (!poll || poll.state === "ended") return;
    // ⚠️ ParticipantActive, not ParticipantConnected: the library raises the
    // latter before the newcomer can receive data, and a message sent then is lost.
    const greet = (participant: Participant) =>
      participant.identity !== room.localParticipant.identity && void send(poll, [participant.identity]).catch(() => undefined);
    // A teacher who reloaded tells the whole room again (whoever came meanwhile was greeted by nobody).
    const announce = () => void send(poll).catch(() => undefined);
    room.on(RoomEvent.ParticipantActive, greet).on(RoomEvent.Connected, announce);
    return () => {
      room.off(RoomEvent.ParticipantActive, greet).off(RoomEvent.Connected, announce);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `send` reads only `room`
  }, [room, poll]);

  const voters = useMemo(() => (poll ? tally(poll, votes) : []), [poll, votes]);
  // Once closed, the teacher reads the same numbers the class was sent.
  const shown = poll?.counts ?? voters.map((list) => list.length);
  const shownTotal = shown.reduce((sum, n) => sum + n, 0);

  // Names come from the roster (the identity is a uuid, FR-006); read again when a stranger votes.
  const unknown = voters.flat().filter((uuid) => !names.has(uuid)).length;
  useEffect(() => {
    if (!poll || (unknown === 0 && names.size > 0)) return;
    let alive = true;
    classSessions
      .participants(sessionUuid)
      .then((roster) => alive && setNames(new Map(roster.data.map((person) => [person.uuid, person.name]))))
      .catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [poll, unknown, names.size, sessionUuid]);

  const remember = (next: Poll) => {
    setPoll(next.state === "ended" ? null : next);
    try {
      if (next.state !== "ended") sessionStorage.setItem(storageKey(sessionUuid), JSON.stringify(next));
      else sessionStorage.removeItem(storageKey(sessionUuid));
    } catch {
      // ponytail: a private window keeps the poll in memory only.
    }
  };

  /** Sent first, kept second: a poll the class never received is not shown as running. */
  const publish = async (next: Poll): Promise<boolean> => {
    setError("");
    try {
      await send(next);
      remember(next);
      return true;
    } catch {
      setError("تعذّر إرسال التصويت للطلاب. تأكّد من الاتصال وأعد المحاولة.");
      return false;
    }
  };

  const start = () => {
    const choices = options.map((option) => option.trim()).filter(Boolean);
    void publish({ v: 1, id: newPollId(), question: question.trim(), options: choices, state: "open" }).then((ok) => ok && setDrafting(false));
  };

  const ready = options.filter((option) => option.trim() !== "").length >= MIN_OPTIONS;

  if (!poll && !drafting) {
    return (
      <div className="space-y-2">
        <Button variant="ghost" onClick={() => setDrafting(true)}>
          تصويت سريع 🗳
        </Button>
        {error !== "" && <Alert tone="danger" title={error} />}
      </div>
    );
  }

  if (!poll) {
    return (
      <section aria-label="تصويت سريع" className="space-y-3 rounded-2xl border border-line bg-surface-raised p-4">
        <div className="flex flex-wrap gap-2">
          {POLL_PRESETS.map((preset) => (
            <Button
              key={preset.label}
              size="sm"
              variant="secondary"
              onClick={() => {
                setQuestion(preset.question);
                setOptions(preset.options);
              }}
            >
              {preset.label}
            </Button>
          ))}
        </div>
        <label className="flex flex-col gap-1 text-sm">
          <span>السؤال (اختياري لو مكتوب على السبّورة)</span>
          <input
            value={question}
            maxLength={MAX_QUESTION}
            onChange={(event) => setQuestion(event.target.value)}
            className="rounded border border-line bg-surface p-2"
          />
        </label>
        <ol className="space-y-2">
          {options.map((option, index) => (
            <li key={index} className="flex items-center gap-2">
              <input
                aria-label={`الاختيار ${index + 1}`}
                value={option}
                maxLength={MAX_OPTION}
                onChange={(event) => setOptions((all) => all.map((value, i) => (i === index ? event.target.value : value)))}
                className="flex-1 rounded border border-line bg-surface p-2 text-sm"
              />
              {options.length > MIN_OPTIONS && (
                <Button size="sm" variant="ghost" onClick={() => setOptions((all) => all.filter((_, i) => i !== index))}>
                  حذف
                </Button>
              )}
            </li>
          ))}
        </ol>
        <div className="flex flex-wrap gap-2">
          {options.length < MAX_OPTIONS && (
            <Button size="sm" variant="ghost" onClick={() => setOptions((all) => [...all, ""])}>
              + اختيار
            </Button>
          )}
          <Button size="sm" disabled={!ready} onClick={start}>
            ابدأ التصويت
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setDrafting(false)}>
            إلغاء
          </Button>
        </div>
        {error !== "" && <Alert tone="danger" title={error} />}
      </section>
    );
  }

  return (
    <section aria-label="تصويت سريع" className="space-y-3 rounded-2xl border border-line bg-surface-raised p-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="font-semibold">{poll.question || "تصويت"}</h3>
        <span className="text-sm text-ink-muted">
          {poll.state === "open" ? "التصويت مفتوح" : "النتيجة ظاهرة للطلاب"} · صوّت {arabicNumber(shownTotal)}
        </span>
      </div>
      <ul className="space-y-2">
        {poll.options.map((option, index) => (
          <li key={index} className="space-y-1">
            <ResultBar label={option} count={shown[index] ?? 0} total={shownTotal} />
            {(voters[index]?.length ?? 0) > 0 && (
              <p className="text-xs text-ink-muted">{voters[index].map((uuid) => names.get(uuid) ?? "طالب").join("، ")}</p>
            )}
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        {poll.state === "open" ? (
          <Button size="sm" onClick={() => void publish({ ...poll, state: "closed", counts: voters.map((list) => list.length) })}>
            اقفل التصويت وأعلن النتيجة
          </Button>
        ) : (
          <Button size="sm" variant="secondary" onClick={() => void publish({ ...poll, state: "ended" }).then((ok) => ok && setDrafting(true))}>
            تصويت جديد
          </Button>
        )}
        <Button size="sm" variant="ghost" onClick={() => void publish({ ...poll, state: "ended" })}>
          إنهاء وإخفاء
        </Button>
      </div>
      {error !== "" && <Alert tone="danger" title={error} />}
    </section>
  );
}

/** Per person: a tab another student signs into must not vote in their name. */
const voteKey = (identity: string) => `live-poll-vote:${identity}`;

function StudentPoll() {
  const room = useRoomContext();
  const { localParticipant } = useLocalParticipant();
  const { attributes } = useParticipantAttributes({ participant: localParticipant });
  const [poll, setPoll] = useState<Poll | null>(null);
  const [error, setError] = useState("");

  // Only a host can publish data (a student's ticket cannot), so what arrives here is the teacher's.
  useEffect(() => {
    const receive = (payload: Uint8Array, _from?: Participant, _kind?: unknown, topic?: string) => {
      if (topic !== POLL_TOPIC) return;
      const next = decodePoll(payload);
      if (next) setPoll(next.state === "ended" ? null : next);
    };
    room.on(RoomEvent.DataReceived, receive);
    return () => {
      room.off(RoomEvent.DataReceived, receive);
    };
  }, [room]);

  /*
   * A vote lives on the connection, and a reload is a new one: the teacher's
   * count dropped her vote and her choice was no longer marked (measured in a
   * live room, 2026-10-05). This tab remembers it and says it again.
   */
  const current = attributes?.[POLL_ATTRIBUTE];
  useEffect(() => {
    if (poll?.state !== "open" || readVote(current, poll) !== null) return;
    let saved: string | null = null;
    try {
      saved = sessionStorage.getItem(voteKey(localParticipant.identity));
    } catch {
      return; // no storage: the student answers again
    }
    if (saved && readVote(saved, poll) !== null) localParticipant.setAttributes({ [POLL_ATTRIBUTE]: saved }).then(() => setError(""), () => undefined);
  }, [poll, current, localParticipant]);

  if (!poll) return null;

  const mine = readVote(current, poll);
  const total = poll.counts?.reduce((sum, n) => sum + n, 0) ?? 0;

  const vote = (choice: number) => {
    setError("");
    const value = voteValue(poll.id, choice);
    // Kept as pressed, not as confirmed: a reload before the answer came back said the earlier choice again.
    try {
      sessionStorage.setItem(voteKey(localParticipant.identity), value);
    } catch {
      // a private window: the vote stands until a reload
    }
    localParticipant
      // The one key: setAttributes merges, and resending the others could raise a hand the teacher just cleared.
      .setAttributes({ [POLL_ATTRIBUTE]: value })
      .catch(() => setError("لم يصل اختيارك. أعد المحاولة."));
  };

  return (
    <section aria-label="تصويت" className="space-y-3 rounded-2xl border border-line bg-surface-raised p-4">
      <h3 className="font-semibold">{poll.question || "تصويت من المدرّس"}</h3>
      {poll.state === "open" ? (
        <div className="flex flex-wrap gap-2">
          {poll.options.map((option, index) => (
            <Button key={index} variant={mine === index ? "primary" : "secondary"} pressed={mine === index} onClick={() => vote(index)}>
              {option}
            </Button>
          ))}
        </div>
      ) : (
        <ul className="space-y-2">
          {poll.options.map((option, index) => (
            <li key={index}>
              <ResultBar label={mine === index ? `${option} (اختيارك)` : option} count={poll.counts?.[index] ?? 0} total={total} />
            </li>
          ))}
        </ul>
      )}
      {poll.state === "open" && (
        <p className="text-xs text-ink-muted">{mine === null ? "اختر إجابة. تقدر تغيّرها لحد ما المدرّس يقفل التصويت." : "وصل اختيارك. النتيجة تظهر لما المدرّس يقفل التصويت."}</p>
      )}
      {error !== "" && <Alert tone="warning" title={error} />}
    </section>
  );
}

function ResultBar({ label, count, total }: { label: string; count: number; total: number }) {
  const share = percent(count, total);
  return (
    <div className="space-y-1">
      <div className="flex justify-between text-sm">
        <span>{label}</span>
        <span className="text-ink-muted">
          {arabicNumber(count)} · {arabicNumber(share)}٪
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-line" aria-hidden="true">
        <div className="h-full rounded-full bg-primary" style={{ width: `${share}%` }} />
      </div>
    </div>
  );
}
