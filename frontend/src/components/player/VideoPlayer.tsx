"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { Watermark } from "@/components/player/Watermark";
import { Alert } from "@/components/ui/Alert";
import { type PlaybackGrant } from "@/lib/media";

/**
 * A native <video> element, and deliberately nothing more.
 *
 * The browser already gives us everything the requirements ask for: full
 * keyboard control, playback speed, captions via <track>, and no download button
 * via controlsList. Its built-in controls are also more accessible than any
 * custom set we would write to replace them, which is most of why the
 * accessibility criterion is reachable at all.
 *
 * What it does not give is HLS outside Safari — and there is no HLS manifest to
 * play yet, because there is no provider producing one. Rather than shipping a
 * player library for a format nothing emits, an unsupported format says so in
 * words instead of showing a black rectangle.
 */
export function VideoPlayer({ grant }: { grant: PlaybackGrant }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState("");
  const [source, setSource] = useState(grant.manifest_url);

  const canPlay = grant.format === "progressive" || isNativeHlsSupported();

  // Renewal belongs to the watermark, not here — see Watermark.tsx. This
  // component only reacts to what that loop reports.
  const onRenewed = useCallback((manifestUrl: string) => setSource(manifestUrl), []);
  const onStopped = useCallback((message: string) => setError(message), []);

  // Pick up where they left off (FR-036).
  useEffect(() => {
    const video = videoRef.current;
    if (video === null || grant.resume_at_seconds <= 0) return;

    video.currentTime = grant.resume_at_seconds;
  }, [grant.resume_at_seconds]);

  if (!canPlay) {
    return (
      <Alert tone="warning" title="صيغة غير مدعومة">
        متصفّحك لا يدعم تشغيل هذا الفيديو. جرّب متصفّحاً آخر أو حدّث متصفّحك.
      </Alert>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {error !== "" && <Alert tone="danger" title="توقّف التشغيل">{error}</Alert>}

      <div className="relative">
        <video
          ref={videoRef}
          src={source}
          controls
          // Removes the download item from the browser's own menu. Not a
          // protection on its own — the grant expiring is — but there is no
          // reason to offer the button.
          controlsList="nodownload"
          preload="metadata"
          playsInline
          onError={() => setError("تعذّر تشغيل الفيديو. حدّث الصفحة وحاول مجدداً.")}
          className="w-full rounded-xl bg-surface"
        >
          {grant.captions.map((caption) => (
            <track
              key={caption.uuid}
              kind="captions"
              srcLang={caption.language}
              label={caption.language === "ar" ? "العربية" : caption.language}
              default={caption.is_default}
            />
          ))}
        </video>

        <Watermark
          grant={grant}
          videoRef={videoRef}
          onRenewed={onRenewed}
          onStopped={onStopped}
        />
      </div>
    </div>
  );
}

/** Safari plays HLS from a plain <video>; nothing else does without a library. */
function isNativeHlsSupported(): boolean {
  if (typeof document === "undefined") return false;

  const probe = document.createElement("video");

  return probe.canPlayType("application/vnd.apple.mpegurl") !== "";
}
