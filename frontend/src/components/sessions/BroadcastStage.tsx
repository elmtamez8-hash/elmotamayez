"use client";

import { useEffect, useState } from "react";
import {
  LiveKitRoom,
  ParticipantTile,
  RoomAudioRenderer,
  useIsSpeaking,
  useLocalParticipant,
  useLocalParticipantPermissions,
  useParticipantAttributes,
  useTracks,
  VideoTrack,
} from "@livekit/components-react";
import { ScreenSharePresets, Track, type ScreenShareCaptureOptions, type TrackPublishOptions } from "livekit-client";
import type { TrackReferenceOrPlaceholder } from "@livekit/components-core";

import { LivePoll } from "@/components/sessions/LivePoll";
import { ParticipantsPanel } from "@/components/sessions/ParticipantsPanel";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { type JoinTicket } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { mayPublish, SOURCE_MICROPHONE, SOURCE_SCREEN_SHARE } from "@/lib/room-permissions";

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
/**
 * A shared screen is mostly a whiteboard or a page of text (spec 039, T111,
 * owner-approved 2026-10-03), so it is sent for legibility, not motion:
 * - `contentHint: "detail"` — the encoder keeps edges sharp and drops frames
 *   before it drops resolution;
 * - sent at 1080p15 (the library's default encoding); the capture size is left
 *   to the library, which deliberately sets none on Safari 17 (a Safari bug);
 * - the tab pane is offered first, as the board's hint says («شارك هذا التبويب»);
 * - the weaker layer a student on mobile data receives is 720p at 5 fps instead
 *   of 540p at 15: handwriting stays readable, a video shown that way stutters.
 */
const SHARE_CAPTURE: ScreenShareCaptureOptions = {
  contentHint: "detail",
  video: { displaySurface: "browser" },
  surfaceSwitching: "include",
};
const SHARE_PUBLISH: TrackPublishOptions = {
  screenShareSimulcastLayers: [ScreenSharePresets.h720fps5],
};

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
        /*
          ⚠️ THE STUDENT ARRIVES WITH BOTH OFF, AND THESE WERE BARE `audio video`
          — WHICH THE LIBRARY READS AS «PUBLISH IMMEDIATELY ON CONNECT».

          So a fourteen-year-old opening the lesson on her phone had her room on
          the class stage, and in the egress file, before she had touched
          anything. `RecordingNotice` is drawn directly above this and says «إن
          لم ترغب في الظهور، أغلِقِ الكاميرا والميكروفون» — an opt-out offered a
          moment after the choice was taken, which its own docblock argues is not
          a notice at all.

          The host is the exception, and deliberately: a teacher who has to press
          two buttons before the class can hear them is the first thirty seconds
          of every lesson spent on plumbing. The role comes from the SIGNED
          ticket, so this cannot be flipped from the browser.
        */
        audio={ticket.role === "host"}
        video={ticket.role === "host"}
        // The room is left when the component unmounts, which is what makes
        // closing the tab a departure rather than a ghost participant.
        //
        // ⚠️ `userMessage`, not `err.message`: this is the library's own error and
        // it is English. The likeliest one — a failed ICE negotiation on mobile
        // data — used to land in the Alert title verbatim, in English, under an
        // Arabic heading, on a student's phone.
        onError={(err: Error) => setError(userMessage(err))}
      >
        <Stage />
        {/* Plays everyone else's audio. Without it the room is silent film. */}
        <RoomAudioRenderer />
        {/* The role from the SIGNED ticket decides what is drawn; the server's
            grant decides what works (the host's has no hand to raise). */}
        <SelfControls isHost={ticket.role === "host"} />
        {/* «تصويت سريع»: the teacher asks, each student answers from here (lib/live-poll.ts). */}
        <LivePoll sessionUuid={sessionUuid} isHost={ticket.role === "host"} />
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

  const { main, strip } = arrangeStage(tracks);
  const tile = (trackRef: TrackReferenceOrPlaceholder) => (
    <StageTile key={`${trackRef.participant.identity}-${trackRef.source}`} trackRef={trackRef} />
  );

  if (main) {
    return (
      <div className="space-y-3" role="region" aria-label="مسرح البثّ">
        {tile(main)}
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">{strip.map(tile)}</div>
      </div>
    );
  }

  return (
    <div
      className="grid grid-cols-1 gap-3 sm:grid-cols-2"
      role="region"
      aria-label="مسرح البثّ"
    >
      {tracks.map(tile)}
    </div>
  );
}

/**
 * A shared screen is the lesson — usually the board — so it takes the whole
 * width and every camera moves to a strip of small tiles under it (owner,
 * 2026-10-05). Half the stage, beside the teacher's face, made the writing
 * hard to read, and on a phone worse. Two shares at once: the first is shown
 * large, the other joins the strip.
 */
export function arrangeStage<T extends { source: string; publication?: unknown }>(tracks: readonly T[]): { main: T | null; strip: T[] } {
  const main = tracks.find((t) => t.source === Track.Source.ScreenShare && t.publication !== undefined) ?? null;
  return { main, strip: main ? tracks.filter((t) => t !== main) : [...tracks] };
}

/**
 * One tile, and the ring that says who is talking.
 *
 * Its own component so `useIsSpeaking` subscribes PER PARTICIPANT — a single
 * hook at the top of the grid could only watch one of them, and the whole point
 * is that the ring moves.
 */
function StageTile({ trackRef }: { trackRef: TrackReferenceOrPlaceholder }) {
  const isSpeaking = useIsSpeaking(trackRef.participant);

  return (
    <div
      className={`aspect-video overflow-hidden rounded-2xl border-2 bg-surface ${
        // ⚠️ `secondary`, because THERE IS NO `success` TOKEN. `border-success`
        // named nothing in `@theme`, so Tailwind emitted no rule and the border
        // fell back to `currentColor` — an ink ring that says nothing about who
        // is talking. Its test passed on the aria-label, over an invisible mark.
        isSpeaking ? "border-secondary" : "border-line"
      }`}
    >
      {/*
        ⚠️ CHILDREN, BECAUSE THE LIBRARY'S DEFAULT TILE PRINTS THE IDENTITY —
        AND OUR IDENTITY IS A UUID. `ParticipantTile` renders
        `participant.name || participant.identity`, and the ticket never sets a
        name (`FR-006`): the identity is echoed by the provider to everyone in
        the room, so a name inside it is a name we no longer control. The result
        was `a270bec9-2a00-…` written across the video of a lesson.

        The names live in the participants list under the stage, where they come
        from our own authenticated route. Repeating them here would mean carrying
        the roster into this component for a caption nobody reads while looking
        at a face.
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
  );
}

/**
 * My microphone, my camera, my screen — nobody else's.
 *
 * ⚠️ THE HOST GETS NO «ارفع يدك» AND NO «لم أفهم» (owner decision 2026-09-30).
 * Both are a student's request to the person teaching, and the teacher raising
 * a hand in their own lesson put a ✋ in the queue they are meant to be
 * reading. The server backs it: a host ticket cannot write its own attributes.
 *
 * ⚠️ AND WHAT A STUDENT MAY PUBLISH IS READ FROM HER LIVE PERMISSIONS, never
 * assumed. The host's «كتم» / «اكتم الجميع» take the microphone out of the
 * permission the provider pushes to this browser, and the screen is shared
 * only once the host allowed it — so the button follows the grant as it
 * changes, mid-lesson, with no reload.
 */
function SelfControls({ isHost }: { isHost: boolean }) {
  const { localParticipant, isMicrophoneEnabled, isCameraEnabled, isScreenShareEnabled } =
    useLocalParticipant();
  const { attributes } = useParticipantAttributes({ participant: localParticipant });
  const permissions = useLocalParticipantPermissions();
  const [error, setError] = useState("");

  // Unknown (not connected yet) opens the microphone — nothing was refused —
  // and keeps the screen closed, because a student's screen is closed until
  // the host says otherwise.
  const micAllowed = isHost || mayPublish(permissions, SOURCE_MICROPHONE) !== false;
  const screenAllowed = isHost || mayPublish(permissions, SOURCE_SCREEN_SHARE) === true;

  /*
   * The grant is the rule and the provider enforces it; this only keeps the
   * browser's own state honest, so a microphone the host just took does not
   * sit «on» in the button with nothing going out.
   */
  useEffect(() => {
    if (!micAllowed && isMicrophoneEnabled) {
      localParticipant.setMicrophoneEnabled(false).catch(() => undefined);
    }
  }, [micAllowed, isMicrophoneEnabled, localParticipant]);

  useEffect(() => {
    if (!screenAllowed && isScreenShareEnabled) {
      localParticipant.setScreenShareEnabled(false).catch(() => undefined);
    }
  }, [screenAllowed, isScreenShareEnabled, localParticipant]);

  const handRaised = attributes?.hand === "1";
  const confused = attributes?.confused === "1";

  /**
   * One signal, on or off.
   *
   * ⚠️ AN EMPTY STRING TURNS IT OFF, never `"0"`. The provider DELETES an
   * attribute whose value is empty, which is also what the teacher's «امسح
   * الإشارات» does server-side — two spellings of "lowered" would leave the room
   * carrying dead keys that only one of the two paths knows to ignore.
   *
   * It deliberately does NOT go through `run()`: nothing here touches a camera
   * or a microphone, so the secure-context guard would refuse the two controls
   * that still work for a student who cannot publish anything — which is exactly
   * the student who most needs to ask.
   */
  const signal = (key: "hand" | "confused", on: boolean): Promise<void> => {
    setError("");

    return localParticipant
      // The one key: setAttributes merges, and resending the other could undo the teacher's «امسح الإشارات».
      .setAttributes({ [key]: on ? "1" : "" })
      .catch((e: unknown) => setError(userMessage(e)));
  };

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

      {!micAllowed && (
        <p role="status" className="text-sm text-ink-muted">
          كتمك المدرّس — ارفع يدك لتطلب الكلام.
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        {/*
          ⚠️ ATTRIBUTES, NOT MESSAGES, AND NOT ROWS IN OUR DATABASE. The provider
          replays attributes to whoever joins later, so a hand raised before the
          teacher opened their laptop is still up when they arrive — a data
          message would have been delivered to an empty room and lost. And they
          need no endpoint of ours: a raised hand is worth nothing once the
          lesson ends, so storing it would be a table that only ever grows.
        */}
        {!isHost && (
          <Button
            variant={handRaised ? "secondary" : "ghost"}
            onClick={() => void signal("hand", !handRaised)}
          >
            {handRaised ? "أنزل يدي" : "ارفع يدك ✋"}
          </Button>
        )}

        {/*
          ⚠️ THE QUIET STUDENT'S BUTTON, and it is not a second hand. Raising a
          hand is a request to SPEAK in front of the class, which is exactly what
          the student who is lost will not do — so «لم أفهم» is one tap that
          interrupts nobody, and what the teacher reads is a COUNT rather than a
          list of names. It carries no text on purpose: a free-form «ما فهمت
          إيه؟» is a second chat, unmoderated, in the middle of a lesson.
        */}
        {!isHost && (
          <Button
            variant={confused ? "secondary" : "ghost"}
            onClick={() => void signal("confused", !confused)}
          >
            {confused ? "فهمت الآن" : "لم أفهم 🤔"}
          </Button>
        )}

        <Button
          variant={isMicrophoneEnabled && micAllowed ? "secondary" : "ghost"}
          disabled={!micAllowed}
          onClick={() => run(() => localParticipant.setMicrophoneEnabled(!isMicrophoneEnabled))}
        >
          {isMicrophoneEnabled && micAllowed ? "كتم ميكروفوني" : "تشغيل ميكروفوني"}
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
        {/* Drawn only once the host allowed it: a button that answers «not
            permitted» on press is a button that should not be there. */}
        {screenAllowed && (
          <Button
            variant={isScreenShareEnabled ? "secondary" : "ghost"}
            onClick={() => run(() => localParticipant.setScreenShareEnabled(!isScreenShareEnabled, SHARE_CAPTURE, SHARE_PUBLISH))}
          >
            {isScreenShareEnabled ? "إيقاف مشاركة الشاشة" : "مشاركة الشاشة"}
          </Button>
        )}
      </div>
    </div>
  );
}
