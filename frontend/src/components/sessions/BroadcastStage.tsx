"use client";

import { useState } from "react";
import {
  LiveKitRoom,
  ParticipantTile,
  RoomAudioRenderer,
  useLocalParticipant,
  useParticipantAttributes,
  useTracks,
  VideoTrack,
} from "@livekit/components-react";
import { Track } from "livekit-client";

import { ParticipantsPanel } from "@/components/sessions/ParticipantsPanel";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { type JoinTicket } from "@/lib/class-sessions";
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
          for every one of the host calls inside. This only decides what is worth
          drawing — the list itself belongs to everyone in the room, the way the
          room's chat does.
        */}
        <ParticipantsPanel sessionUuid={sessionUuid} isHost={ticket.role === "host"} />
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
          {/*
            ⚠️ CHILDREN, BECAUSE THE LIBRARY'S DEFAULT TILE PRINTS THE IDENTITY —
            AND OUR IDENTITY IS A UUID. `ParticipantTile` renders
            `participant.name || participant.identity`, and the ticket never sets
            a name (`FR-006`): the identity is echoed by the provider to everyone
            in the room, so a name inside it is a name we no longer control. The
            result was `a270bec9-2a00-…` written across the video of a lesson.

            The names live in the participants list under the stage, where they
            come from our own authenticated route. Repeating them here would mean
            carrying the roster into this component for a caption nobody reads
            while looking at a face.
          */}
          <ParticipantTile trackRef={trackRef}>
            {trackRef.publication === undefined ? (
              <div className="flex h-full items-center justify-center text-sm text-ink-muted">
                الكاميرا مغلقة
              </div>
            ) : (
              <VideoTrack trackRef={trackRef} />
            )}
          </ParticipantTile>
        </div>
      ))}
    </div>
  );
}

/** My microphone, my camera, my screen — nobody else's. */
function SelfControls() {
  const { localParticipant, isMicrophoneEnabled, isCameraEnabled, isScreenShareEnabled } =
    useLocalParticipant();
  const { attributes } = useParticipantAttributes({ participant: localParticipant });
  const [error, setError] = useState("");

  const handRaised = attributes?.hand === "1";

  /**
   * ⚠️ `void promise` was the whole error handling here, and a rejected promise
   * is an unhandled rejection: in development the overlay paints a raw
   * `TypeError` over the lesson, and in **production the button simply does
   * nothing at all** — no message, no reason, on the one control a student
   * needs to be seen. Measured on a real phone (2026-08-26): tapping the
   * microphone answered «Cannot read properties of undefined (reading
   * 'getUserMedia')», which is neither Arabic nor true of anything the reader
   * can act on.
   *
   * The missing-`mediaDevices` case is named SEPARATELY because no generic
   * sentence can describe it: outside a secure context the browser does not
   * expose the API at all, so nothing was refused and no permission dialog will
   * ever appear — the fault is the address the page was opened from. Telling a
   * student «تعذّر الوصول للكاميرا» there sends them to hunt a permission
   * setting that is not the problem.
   */
  const run = (action: () => Promise<unknown>): void => {
    if (typeof navigator === "undefined" || navigator.mediaDevices === undefined) {
      setError(
        "المتصفّح لا يمنح الكاميرا والميكروفون إلا لصفحةٍ فُتحت على عنوانٍ آمن (https). تابع الحصة بالكتابة، أو افتحها من العنوان الآمن.",
      );

      return;
    }

    setError("");
    action().catch((e: unknown) => setError(userMessage(e)));
  };

  return (
    <div className="mt-4 space-y-3">
      {error !== "" && <Alert tone="warning" title={error} />}

      <div className="flex flex-wrap gap-2">
        {/*
          ⚠️ AN ATTRIBUTE, NOT A MESSAGE, AND NOT A ROW IN OUR DATABASE. The
          provider replays attributes to whoever joins later, so a hand raised
          before the teacher opened their laptop is still up when they arrive —
          a data message would have been delivered to an empty room and lost.
          And it needs no endpoint of ours: a raised hand is worth nothing once
          the lesson ends, so storing it would be a table that only ever grows.

          It does NOT go through `run()`: nothing here touches a camera or a
          microphone, so the secure-context guard would refuse the one control
          that still works for a student who cannot publish anything — which is
          exactly the student who most needs to ask.
        */}
        <Button
          variant={handRaised ? "secondary" : "ghost"}
          onClick={() => {
            setError("");
            localParticipant
              .setAttributes({ ...attributes, hand: handRaised ? "" : "1" })
              .catch((e: unknown) => setError(userMessage(e)));
          }}
        >
          {handRaised ? "أنزل يدي" : "ارفع يدك ✋"}
        </Button>

        <Button
          variant={isMicrophoneEnabled ? "secondary" : "ghost"}
          onClick={() => run(() => localParticipant.setMicrophoneEnabled(!isMicrophoneEnabled))}
        >
          {isMicrophoneEnabled ? "كتم ميكروفوني" : "تشغيل ميكروفوني"}
        </Button>

        <Button
          variant={isCameraEnabled ? "secondary" : "ghost"}
          onClick={() => run(() => localParticipant.setCameraEnabled(!isCameraEnabled))}
        >
          {isCameraEnabled ? "إيقاف الكاميرا" : "تشغيل الكاميرا"}
        </Button>

        {/* The library asks the browser for the screen. We never call
            getDisplayMedia ourselves — permissions, track lifecycle and the stop
            button the browser draws are all its problem, correctly. */}
        <Button
          variant={isScreenShareEnabled ? "secondary" : "ghost"}
          onClick={() => run(() => localParticipant.setScreenShareEnabled(!isScreenShareEnabled))}
        >
          {isScreenShareEnabled ? "إيقاف مشاركة الشاشة" : "مشاركة الشاشة"}
        </Button>
      </div>
    </div>
  );
}
