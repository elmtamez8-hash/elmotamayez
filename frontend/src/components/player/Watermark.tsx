"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { userMessage } from "@/lib/errors";
import { media, type PlaybackGrant } from "@/lib/media";

/**
 * The identifying overlay — and the component that keeps playback alive.
 *
 * Those two jobs are deliberately the same component. FR-018 asks that hiding
 * the watermark stop playback, and every other way of writing that puts the
 * guard somewhere it can be disabled on its own: "check the overlay is visible,
 * then pause" is client logic, and deleting its line leaves the video playing.
 * Here the renewal request lives inside the overlay, so removing the overlay
 * stops the renewal, the grant expires, and the server refuses the next byte
 * range. Whether the loop stopped in good faith or by tampering, the answer is
 * the same one, and it is the server's.
 *
 * The MutationObserver below is a comfort, not a guard: it makes the stop
 * immediate instead of within the grant's lifetime. The guard is expiry.
 *
 * ponytail: a viewer who fakes the renewal call while hiding the overlay keeps
 * watching. True of every web protection short of DRM, and stated in the spec's
 * scope — the deterrent is that the overlay carries their own name.
 */

/** Corners only: the middle of the frame is where the explanation is (FR-020). */
const POSITIONS = [
  "top-3 start-3",
  "top-3 end-3",
  "bottom-14 end-3",
  "bottom-14 start-3",
] as const;

const ROTATE_SECONDS = 20;

export function Watermark({
  grant,
  videoRef,
  onRenewed,
  onStopped,
}: {
  grant: PlaybackGrant;
  videoRef: React.RefObject<HTMLVideoElement | null>;
  onRenewed: (manifestUrl: string) => void;
  onStopped: (message: string) => void;
}) {
  const hostRef = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState(0);

  const stop = useCallback(
    (message: string) => {
      videoRef.current?.pause();
      onStopped(message);
    },
    [videoRef, onStopped],
  );

  // The renewal loop. Without it the grant simply runs out.
  useEffect(() => {
    const timer = window.setInterval(() => {
      void media
        .renew(grant.grant, videoRef.current?.currentTime ?? 0)
        .then((renewed) => onRenewed(renewed.manifest_url))
        .catch((err: unknown) => stop(userMessage(err)));
    }, grant.renew_after_seconds * 1000);

    return () => window.clearInterval(timer);
  }, [grant.grant, grant.renew_after_seconds, videoRef, onRenewed, stop]);

  // FR-017 — a fixed corner is one a phone camera can be framed to exclude, and
  // one a sticky note covers.
  useEffect(() => {
    const timer = window.setInterval(() => {
      setPosition((current) => (current + 1) % POSITIONS.length);

      const host = hostRef.current;
      if (host !== null && isHidden(host)) stop(STOPPED_MESSAGE);
    }, ROTATE_SECONDS * 1000);

    return () => window.clearInterval(timer);
  }, [stop]);

  // Removed from the DOM: react now rather than waiting for the grant to lapse.
  // Records queued by our own unmount are dropped by disconnect(), so this does
  // not fire on ordinary navigation.
  useEffect(() => {
    const host = hostRef.current;
    const parent = host?.parentElement;
    if (host == null || parent == null) return;

    const observer = new MutationObserver(() => {
      if (!parent.contains(host) || isHidden(host)) stop(STOPPED_MESSAGE);
    });

    observer.observe(parent, { childList: true, subtree: true });
    observer.observe(host, { attributes: true, attributeFilter: ["style", "class", "hidden"] });

    return () => observer.disconnect();
  }, [stop]);

  return (
    <div
      ref={hostRef}
      // Not focusable and not clickable: it sits over the video and must never
      // take a click meant for the controls (FR-020).
      aria-hidden="true"
      className={`pointer-events-none absolute ${POSITIONS[position]} select-none rounded-lg bg-surface-raised/70 px-2 py-1 text-xs text-ink-muted transition-all duration-700`}
    >
      <span>{grant.watermark.name}</span>
      {grant.watermark.phone_masked !== null && (
        <span className="ms-2">{grant.watermark.phone_masked}</span>
      )}
    </div>
  );
}

const STOPPED_MESSAGE = "توقّف التشغيل لأن علامة الحماية لم تعد ظاهرة. حدّث الصفحة للمتابعة.";

/** Hidden by any of the three ways a stylesheet can hide something. */
function isHidden(element: HTMLElement): boolean {
  const style = window.getComputedStyle(element);

  return (
    style.display === "none" ||
    style.visibility === "hidden" ||
    Number(style.opacity) < 0.1 ||
    element.offsetParent === null
  );
}
