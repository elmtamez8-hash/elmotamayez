"use client";

import { useState } from "react";
import {
  LiveKitRoom,
  ParticipantTile,
  RoomAudioRenderer,
  useLocalParticipant,
  useRemoteParticipants,
  useTracks,
} from "@livekit/components-react";
import { Track } from "livekit-client";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { classSessions, type JoinTicket } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";

/**
 * The video, and the only file in the frontend that names the provider.
 *
 * It is a wrapper around the library and nothing more: the door, the ticket, the
 * heartbeat, the host controls and the whole register work exactly as they did
 * before it existed, because attendance never passes through the provider
 * (research §R3). That is why swapping providers is this file and one adapter.
 *
 * ⚠️ The heartbeat is NOT here and must never move here. `PresenceLoop` runs on
 * its own, beside this component: a register that stopped when a camera failed
 * would mark a student absent for a bandwidth problem.
 *
 * ⚠️ No stylesheet from the library is imported. Colours come from `@theme` and
 * spacing from our own tokens — a second design system inside one card is how a
 * product ends up with two greys.
 */
export function BroadcastStage({
  ticket,
  sessionUuid,
}: {
  ticket: JoinTicket;
  sessionUuid: string;
}) {
  const [error, setError] = useState("");

  return (
    <div className="space-y-4">
      {error !== "" && (
        <Alert tone="danger" title="تعذّر الاتصال بالبثّ">
          {error}
        </Alert>
      )}

      <LiveKitRoom
        serverUrl={ticket.room_url}
        token={ticket.token}
        connect
        audio
        video
        // The room is left when the component unmounts, which is what makes
        // closing the tab a departure rather than a ghost participant.
        onError={(err: Error) => setError(err.message)}
      >
        <Stage />
        {/* Plays everyone else's audio. Without it the room is silent film. */}
        <RoomAudioRenderer />
        <SelfControls />
        {/*
          ⚠️ The role comes from the SIGNED TICKET, never from a piece of browser
          state. It is also not the guard: the server checks `host` on the policy
          for every one of these calls. This only decides what is worth drawing.
        */}
        {ticket.role === "host" && <HostControls sessionUuid={sessionUuid} />}
      </LiveKitRoom>
    </div>
  );
}

/**
 * Everyone's camera and anyone's shared screen, in one grid.
 *
 * Laid out by hand rather than with the library's `GridLayout`, which expects
 * the stylesheet we deliberately do not import.
 */
function Stage() {
  const tracks = useTracks(
    [
      { source: Track.Source.Camera, withPlaceholder: true },
      { source: Track.Source.ScreenShare, withPlaceholder: false },
    ],
    { onlySubscribed: false },
  );

  if (tracks.length === 0) {
    return (
      <div
        className="flex aspect-video w-full items-center justify-center rounded-2xl border border-line bg-surface"
        role="region"
        aria-label="مسرح البثّ"
      >
        <p className="text-sm text-ink-muted">جارٍ الاتصال بالغرفة…</p>
      </div>
    );
  }

  return (
    <div
      className="grid grid-cols-1 gap-3 sm:grid-cols-2"
      role="region"
      aria-label="مسرح البثّ"
    >
      {tracks.map((trackRef) => (
        <div
          key={`${trackRef.participant.identity}-${trackRef.source}`}
          className="aspect-video overflow-hidden rounded-2xl border border-line bg-surface"
        >
          <ParticipantTile trackRef={trackRef} />
        </div>
      ))}
    </div>
  );
}

/**
 * The class roll, with the two buttons a teacher needs during a lesson.
 *
 * The identity of every participant IS the student's uuid — the adapter puts it
 * there and never the name (FR-006) — which is why this can name a target
 * without the browser ever learning anything else about them.
 *
 * ⚠️ 501 is a real answer here, not a bug. A provider that does not claim
 * hostControls refuses loudly, and the teacher must be told in words: a mute
 * button that silently does nothing is worse than no button at all.
 */
function HostControls({ sessionUuid }: { sessionUuid: string }) {
  const participants = useRemoteParticipants();
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");

  const act = async (action: "mute" | "remove", identity: string) => {
    setBusy(`${action}:${identity}`);
    setError("");

    try {
      await classSessions.host(sessionUuid, action, identity);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy("");
    }
  };

  if (participants.length === 0) return null;

  return (
    <div className="mt-6 space-y-3">
      <h3 className="text-sm font-bold text-ink">المشاركون</h3>

      {error !== "" && <Alert tone="danger" title={error} />}

      <ul className="space-y-2">
        {participants.map((participant) => (
          <li
            key={participant.identity}
            className="flex items-center justify-between gap-3 rounded-xl border border-line px-3 py-2"
          >
            <span className="text-sm text-ink">{participant.name || participant.identity}</span>

            <span className="flex gap-2">
              <Button
                variant="ghost"
                loading={busy === `mute:${participant.identity}`}
                onClick={() => void act("mute", participant.identity)}
              >
                كتم
              </Button>
              <Button
                variant="danger"
                loading={busy === `remove:${participant.identity}`}
                onClick={() => void act("remove", participant.identity)}
              >
                إخراج
              </Button>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** My microphone, my camera, my screen — nobody else's. */
function SelfControls() {
  const { localParticipant, isMicrophoneEnabled, isCameraEnabled, isScreenShareEnabled } =
    useLocalParticipant();

  return (
    <div className="mt-4 flex flex-wrap gap-2">
      <Button
        variant={isMicrophoneEnabled ? "secondary" : "ghost"}
        onClick={() => void localParticipant.setMicrophoneEnabled(!isMicrophoneEnabled)}
      >
        {isMicrophoneEnabled ? "كتم ميكروفوني" : "تشغيل ميكروفوني"}
      </Button>

      <Button
        variant={isCameraEnabled ? "secondary" : "ghost"}
        onClick={() => void localParticipant.setCameraEnabled(!isCameraEnabled)}
      >
        {isCameraEnabled ? "إيقاف الكاميرا" : "تشغيل الكاميرا"}
      </Button>

      {/* The library asks the browser for the screen. We never call
          getDisplayMedia ourselves — permissions, track lifecycle and the stop
          button the browser draws are all its problem, correctly. */}
      <Button
        variant={isScreenShareEnabled ? "secondary" : "ghost"}
        onClick={() => void localParticipant.setScreenShareEnabled(!isScreenShareEnabled)}
      >
        {isScreenShareEnabled ? "إيقاف مشاركة الشاشة" : "مشاركة الشاشة"}
      </Button>
    </div>
  );
}
