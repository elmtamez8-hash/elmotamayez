"use client";

import { useCallback, useEffect, useState } from "react";
import {
  useParticipantAttributes,
  useParticipants,
} from "@livekit/components-react";
import type { Participant } from "livekit-client";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { classSessions, type RoomParticipant } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";

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
  const [error, setError] = useState("");

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
          setRoster(new Map((response.data ?? []).map((person) => [person.uuid, person])));
        })
        // A roster that will not load changes nothing about what the room can do:
        // the lesson plays, the rows fall back to «مشارك», and the host buttons
        // still work — they target the identity, which comes from the provider.
        .catch(() => undefined),
    [sessionUuid],
  );

  useEffect(() => {
    void reload();
  }, [reload, participants.length]);

  const act = async (action: "mute" | "remove", identity: string) => {
    setBusy(`${action}:${identity}`);
    setError("");

    try {
      await classSessions.host(sessionUuid, action, identity);
      // Removing somebody drops them out of `participants`, which reloads the
      // roster anyway — but not before the teacher has looked, and the list of
      // who is out is the only place they can be let back in.
      await reload();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy("");
    }
  };

  const actOnRoom = async (action: "mute-all" | "remove-all" | "lower-hands") => {
    setBusy(action);
    setError("");

    try {
      await classSessions.host(sessionUuid, action);
      await reload();
    } catch (err: unknown) {
      setError(userMessage(err));
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
    setError("");

    try {
      await classSessions.host(sessionUuid, "readmit", uuid);
      await reload();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy("");
    }
  };

  // Nobody connected still means something to draw for the host: whoever they
  // put out is only reachable from this panel.
  const removed = [...roster.values()].filter((person) => person.is_removed === true);

  if (participants.length === 0 && removed.length === 0) return null;

  return (
    <div className="mt-6 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-ink">
          المشاركون (<bdi>{participants.length}</bdi>)
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
              أنزِل الأيدي
            </Button>
            <Button
              variant="ghost"
              loading={busy === "mute-all"}
              onClick={() => void actOnRoom("mute-all")}
            >
              اكتم الجميع
            </Button>
            <Button
              variant="danger"
              loading={busy === "remove-all"}
              onClick={() => void actOnRoom("remove-all")}
            >
              أخرِج الجميع
            </Button>
          </span>
        )}
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      <ul className="space-y-2">
        {participants.map((participant) => (
          <ParticipantRow
            key={participant.identity}
            participant={participant}
            person={roster.get(participant.identity)}
            isHost={isHost}
            busy={busy}
            onAct={act}
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
  onAct,
}: {
  participant: Participant;
  person: RoomParticipant | undefined;
  isHost: boolean;
  busy: string;
  onAct: (action: "mute" | "remove", identity: string) => Promise<void>;
}) {
  const { attributes } = useParticipantAttributes({ participant });
  /*
   * ⚠️ READ AS A BOOLEAN, NEVER PRINTED. Attributes are written by the client
   * that owns them — that is what makes a hand raise instantly without a round
   * trip — so a participant can put any sentence in there. One key, compared to
   * one value, rendered as an icon.
   */
  const handRaised = attributes?.hand === "1";
  const name = person?.name ?? "مشارك";

  return (
    <li className="flex items-center justify-between gap-3 rounded-xl border border-line px-3 py-2">
      <span className="flex min-w-0 items-center gap-3">
        <Avatar url={person?.avatar_url ?? null} name={name} />

        <span className="flex min-w-0 flex-col gap-1">
          <span className="flex items-center gap-2">
            <span className="truncate text-sm text-ink">{name}</span>

            {handRaised && (
              <span
                className="text-base"
                role="img"
                aria-label={`${name} يرفع يده`}
                title="يرفع يده"
              >
                ✋
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
          <Button
            variant="danger"
            loading={busy === `remove:${participant.identity}`}
            onClick={() => void onAct("remove", participant.identity)}
          >
            إخراج
          </Button>
        </span>
      )}
    </li>
  );
}

/**
 * The photo, or the initial.
 *
 * A plain `<img>` rather than `next/image`: the path comes from the API, and the
 * repository's own note says the three `next/image` call sites all pass literal
 * `/public` paths — passing a server-supplied one through the optimiser is what
 * makes the outstanding `sharp` advisory live.
 */
function Avatar({ url, name }: { url: string | null; name: string }) {
  if (url === null) {
    return (
      <span
        aria-hidden="true"
        className="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-muted text-sm font-bold text-ink-muted"
      >
        {name.trim().charAt(0)}
      </span>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={url}
      alt=""
      className="size-9 shrink-0 rounded-full object-cover"
      loading="lazy"
    />
  );
}
